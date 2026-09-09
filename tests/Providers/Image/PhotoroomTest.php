<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class PhotoroomTest extends TestCase
{
    use MakesPrismaRequests;


    #[DataProvider('methods')]
    public function testMethods( string $method, array $args, array $options, array $expected, array $files ) : void
    {
        $response = $this->prisma( 'image', 'photoroom', ['api_key' => 'test'] )
            ->response( 'RESULT', ['Content-Type' => 'image/webp'] )
            ->ensure( $method )->$method( ...[...$args, $options + [
                'unknown' => 'ignored', 'removeBackground' => false, 'imageFile' => 'ignored',
                'background.prompt' => 'ignored', 'editWithAI.prompt' => 'ignored',
                'imageFromPrompt.prompt' => 'ignored', 'export.format' => 'webp',
            ]] );

        $request = $this->requests()[0];
        $this->assertSame( 'POST', $request->getMethod() );
        $this->assertSame( 'https://image-api.photoroom.com/v2/edit', (string) $request->getUri() );
        $this->assertSame( 'test', $request->getHeaderLine( 'x-api-key' ) );
        $this->assertStringContainsString( 'multipart/form-data', $request->getHeaderLine( 'Content-Type' ) );

        // Inspect the actual wire form, preserving dotted Photoroom field names.
        preg_match_all( '/Content-Disposition: form-data; name="([^"]+)"[^\r]*\r\n.*?\r\n\r\n(.*?)\r\n--/s', (string) $request->getBody(), $parts, PREG_SET_ORDER );
        $fields = [];

        foreach( $parts as $part ) {
            $fields[$part[1]] = $part[2];
        }

        $expected += ['export.format' => 'webp'];

        if( $files ) {
            $expected += ['referenceBox' => 'originalImage'];
        }

        $this->assertEquals( $expected + $files, $fields );
        $this->assertSame( 'RESULT', $response->binary() );
        $this->assertSame( 'image/webp', $response->mimeType() );
    }


    public static function methods() : array
    {
        $image = Image::fromBinary( 'SOURCE', 'image/png' );
        $bg = Image::fromBinary( 'BACKGROUND', 'image/png' );
        $files = ['imageFile' => 'SOURCE'];

        return [
            'background' => ['background', [$image, 'A beach'], ['background.seed' => 42], ['removeBackground' => 'true', 'background.prompt' => 'A beach', 'background.seed' => '42'], $files],
            'detext' => ['detext', [$image], [], ['removeBackground' => 'false', 'textRemoval.mode' => 'ai.all'], $files],
            'natural text' => ['detext', [$image], ['textRemoval.mode' => 'ai.natural'], ['removeBackground' => 'false', 'textRemoval.mode' => 'ai.natural'], $files],
            'imagine' => ['imagine', ['A fox', []], ['imageFromPrompt.seed' => 7, 'imageFromPrompt.size' => 'SQUARE_HD'], ['removeBackground' => 'false', 'imageFromPrompt.prompt' => 'A fox', 'imageFromPrompt.seed' => '7', 'imageFromPrompt.size' => 'SQUARE_HD'], []],
            'isolate' => ['isolate', [$image], ['keepExistingAlphaChannel' => 'auto', 'padding' => '10px'], ['removeBackground' => 'true', 'background.color' => 'transparent', 'keepExistingAlphaChannel' => 'auto', 'padding' => '10px'], $files],
            'relocate' => ['relocate', [$image, $bg], ['background.scaling' => 'fit'], ['removeBackground' => 'true', 'background.scaling' => 'fit'], $files + ['background.imageFile' => 'BACKGROUND']],
            'repaint' => ['repaint', [$image, 'Make it red'], ['editWithAI.seed' => 0], ['removeBackground' => 'false', 'editWithAI.mode' => 'ai.auto', 'editWithAI.prompt' => 'Make it red', 'editWithAI.seed' => '0'], $files],
            'upscale' => ['upscale', [$image, 4], ['outputSize' => '10x10', 'padding' => '20px'], ['removeBackground' => 'false', 'upscale.mode' => 'ai.fast'], $files],
            'slow upscale' => ['upscale', [$image, 4], ['upscale.mode' => 'ai.slow'], ['removeBackground' => 'false', 'upscale.mode' => 'ai.slow'], $files],
        ];
    }


    #[DataProvider('invalidInputs')]
    public function testInvalidInputs( string $method, array $args ) : void
    {
        $this->prisma( 'image', 'photoroom', ['api_key' => 'test'] );

        try {
            $this->provider()->$method( ...$args );
            $this->fail( 'Expected invalid input to be rejected' );
        } catch( PrismaException $e ) {
            $this->assertSame( [], $this->requests() );
        }
    }


    public static function invalidInputs() : array
    {
        $image = Image::fromUrl( 'https://example.com/must-not-be-downloaded.png' );

        return [
            'references' => ['imagine', ['A fox', [$image]]],
            'factor' => ['upscale', [$image, 2]],
            'upscale mode' => ['upscale', [$image, 4, ['upscale.mode' => 'invalid']]],
            'text mode' => ['detext', [$image, ['textRemoval.mode' => 'invalid']]],
        ];
    }


    public function testLocalImageAndCustomUrl() : void
    {
        $image = Image::fromLocalPath( dirname( __DIR__, 2 ) . '/Integration/assets/cat.png' );
        $this->prisma( 'image', 'photoroom', ['api_key' => 'test', 'url' => 'https://example.com'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )->isolate( $image );

        $request = $this->requests()[0];
        $this->assertSame( 'https://example.com/v2/edit', (string) $request->getUri() );
        $this->assertStringContainsString( (string) $image->binary(), (string) $request->getBody() );
        $this->assertStringContainsString( 'filename="cat.png"', (string) $request->getBody() );
    }


    #[DataProvider('errors')]
    public function testHttpErrors( int $status, string $exception ) : void
    {
        $provider = $this->prisma( 'image', 'photoroom', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Request failed']], [], $status );
        $provider->withClientRetry( 0 );
        $this->expectException( $exception );
        $this->expectExceptionMessage( 'Request failed' );
        $provider->imagine( 'A fox' );
    }


    public static function errors() : array
    {
        return [[400, BadRequestException::class], [429, RateLimitException::class]];
    }


    public function testCapabilities() : void
    {
        $provider = Prisma::image()->using( 'photoroom', ['api_key' => 'test'] );

        foreach( ['uncrop', 'inpaint', 'erase', 'describe', 'recognize', 'vectorize'] as $method ) {
            $this->assertFalse( $provider->has( $method ) );
        }
    }


    public function testMissingApiKey() : void
    {
        $this->expectException( PrismaException::class );
        Prisma::image()->using( 'photoroom', [] );
    }
}
