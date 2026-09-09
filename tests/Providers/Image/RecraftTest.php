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


class RecraftTest extends TestCase
{
    use MakesPrismaRequests;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';


    public function testImagine() : void
    {
        $response = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => self::PNG], ['b64_json' => self::PNG]], 'credits' => 4, 'id' => 'job'] )
            ->ensure( 'imagine' )->imagine( 'A fox', [], [
                'n' => 2, 'size' => '1024x1024', 'random_seed' => 42,
                'controls' => ['colors' => [['rgb' => [255, 0, 0]]]],
                'response_format' => 'b64_json', 'unknown' => true, 'prompt' => 'ignored',
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $this->assertSame( 'POST', $request->getMethod() );
            $this->assertSame( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertSame( 'https://external.api.recraft.ai/v1/images/generations', (string) $request->getUri() );
            $this->assertSame( 'recraftv4_1', $body['model'] );
            $this->assertSame( 'A fox', $body['prompt'] );
            $this->assertSame( 2, $body['n'] );
            $this->assertSame( 42, $body['random_seed'] );
            $this->assertSame( '1024x1024', $body['size'] );
            $this->assertSame( [255, 0, 0], $body['controls']['colors'][0]['rgb'] );
            $this->assertSame( 'b64_json', $body['response_format'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertCount( 2, $response->files() );
        $this->assertSame( base64_decode( self::PNG ), $response->binary() );
        $this->assertSame( 'image/png', $response->mimeType() );
        $this->assertSame( 4.0, $response->usage()['used'] );
        $this->assertSame( 'job', $response->meta()['id'] );
    }


    public function testStyleReferences() : void
    {
        $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/output.png']], 'style_id' => 'style'] )
            ->imagine( 'A fox', [
                Image::fromBase64( self::PNG, 'image/png' ),
                Image::fromUrl( 'https://example.com/reference.png' ),
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $this->assertSame( 'recraftv4_styles', $body['model'] );
            $this->assertSame( ['data:image/png;base64,' . self::PNG, 'https://example.com/reference.png'], $body['style_reference_urls'] );
        } );
    }


    public function testCustomModelAndUrl() : void
    {
        $response = $this->prisma( 'image', 'recraft', ['api_key' => 'test', 'url' => 'https://example.com'] )
            ->response( ['data' => [['url' => 'https://example.com/output.svg']]] )
            ->model( 'recraftv3_vector' )->imagine( 'A logo' );

        $this->assertPrismaRequest( function( $request ) {
            $this->assertSame( 'https://example.com/v1/images/generations', (string) $request->getUri() );
            $this->assertSame( 'recraftv3_vector', json_decode( (string) $request->getBody(), true )['model'] );
        } );

        $this->assertSame( 'https://example.com/output.svg', $response->first()->url() );
    }


    public function testSvgResponse() : void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256"/></svg>';
        $file = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )
            ->response( ['image' => ['b64_json' => base64_encode( $svg )]] )
            ->isolate( Image::fromBinary( $svg, 'image/svg+xml' ) );

        $this->assertSame( $svg, $file->binary() );
        $this->assertSame( 'image/svg+xml', $file->mimeType() );
    }


    #[DataProvider('operations')]
    public function testEditing( string $method, array $arguments, string $endpoint, array $expected, bool $single ) : void
    {
        $response = $single ? ['image' => ['b64_json' => self::PNG]] : ['data' => [['b64_json' => self::PNG]]];
        $provider = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )->response( $response );
        $file = $provider->ensure( $method )->$method( ...$arguments );

        $this->assertPrismaRequest( function( $request ) use ( $endpoint, $expected ) {
            $this->assertSame( 'https://external.api.recraft.ai/v1/images/' . $endpoint, (string) $request->getUri() );
            $body = json_decode( (string) $request->getBody(), true );

            foreach( $expected as $key => $value ) {
                $this->assertSame( $value, $body[$key], $key );
            }

            foreach( ['unknown', 'mask', 'mode', 'factor'] as $key ) {
                $this->assertArrayNotHasKey( $key, $body );
            }
        } );

        $this->assertSame( base64_decode( self::PNG ), $file->binary() );
        $this->assertSame( 'image/png', $file->mimeType() );
    }


    public static function operations() : array
    {
        $image = Image::fromUrl( 'https://example.com/image.png' );
        $mask = Image::fromUrl( 'https://example.com/mask.png' );
        $input = ['image_url' => 'https://example.com/image.png'];
        $masked = $input + ['mask_url' => 'https://example.com/mask.png'];

        return [
            'background' => ['background', [$image, 'Forest'], 'replaceBackground', $input + ['prompt' => 'Forest', 'model' => 'recraftv3'], false],
            'masked background' => ['background', [$image, 'Forest', ['mask' => $mask]], 'generateBackground', $masked + ['prompt' => 'Forest', 'model' => 'recraftv3'], false],
            'erase' => ['erase', [$image, $mask, ['unknown' => true]], 'eraseRegion', $masked, true],
            'inpaint' => ['inpaint', [$image, $mask, 'Glasses'], 'inpaint', $masked + ['prompt' => 'Glasses', 'model' => 'recraftv3'], false],
            'isolate' => ['isolate', [$image, ['response_format' => 'b64_json']], 'removeBackground', $input + ['response_format' => 'b64_json'], true],
            'repaint' => ['repaint', [$image, 'Winter'], 'imageToImage', $input + ['prompt' => 'Winter', 'model' => 'recraftv4_1', 'strength' => 0.5], false],
            'repaint strength' => ['repaint', [$image, 'Winter', ['strength' => 0, 'random_seed' => 12]], 'imageToImage', $input + ['strength' => 0, 'random_seed' => 12], false],
            'uncrop' => ['uncrop', [$image, 10, 20, 30, 40], 'outpaint', $input + ['expand_top' => 10, 'expand_right' => 20, 'expand_bottom' => 30, 'expand_left' => 40, 'model' => 'recraftv3', 'prompt' => 'Extend the image naturally'], false],
            'uncrop prompt' => ['uncrop', [$image, 0, 20, 0, 0, ['prompt' => 'Forest', 'zoom_out_percentage' => 10]], 'outpaint', $input + ['prompt' => 'Forest', 'zoom_out_percentage' => 10], false],
            'crisp upscale' => ['upscale', [$image, 2], 'crispUpscale', $input, true],
            'creative upscale' => ['upscale', [$image, 4, ['mode' => 'creative']], 'creativeUpscale', $input, true],
        ];
    }


    #[DataProvider('invalidResponses')]
    public function testInvalidResponse( array $response ) : void
    {
        $provider = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )->response( $response );
        $this->expectException( PrismaException::class );
        $provider->repaint( Image::fromUrl( 'https://example.com/image.png' ), 'Winter' );
    }


    public static function invalidResponses() : array
    {
        return [[[]], [['data' => []]], [['data' => 'bad']], [['image' => []]],
            [['data' => ['bad']]], [['data' => [['b64_json' => '!bad!']]]], [['data' => [['url' => '']]]]];
    }


    #[DataProvider('errors')]
    public function testErrors( array $body, int $status, string $exception, string $message ) : void
    {
        $provider = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )->response( $body, [], $status );
        $this->expectException( $exception );
        $this->expectExceptionMessage( $message );
        $provider->repaint( Image::fromUrl( 'https://example.com/image.png' ), 'Winter' );
    }


    public static function errors() : array
    {
        return [
            [['error' => 'Invalid model'], 400, BadRequestException::class, 'Invalid model'],
            [['error' => ['message' => 'Slow down']], 429, RateLimitException::class, 'Slow down'],
            [['message' => 'Invalid mask'], 422, BadRequestException::class, 'Invalid mask'],
        ];
    }


    #[DataProvider('invalidArguments')]
    public function testInvalidArguments( string $method, array $arguments ) : void
    {
        $provider = $this->prisma( 'image', 'recraft', ['api_key' => 'test'] )->provider();

        try {
            $provider->$method( ...$arguments );
            $this->fail( 'Expected invalid arguments to be rejected' );
        } catch( BadRequestException $e ) {
            $this->assertNotEmpty( $e->getMessage() );
            $this->assertCount( 0, $this->requests() );
        }
    }


    public static function invalidArguments() : array
    {
        $image = Image::fromUrl( 'https://example.com/image.png' );

        return [
            'background invalid mask' => ['background', [$image, 'Forest', ['mask' => 'bad']]],
            'imagine conflicting style' => ['imagine', ['Fox', [$image], ['style_id' => 'style']]],
            'imagine too many references' => ['imagine', ['Fox', array_fill( 0, 11, $image )]],
            'imagine invalid reference' => ['imagine', ['Fox', ['bad']]],
            'uncrop negative margin' => ['uncrop', [$image, -1, 0, 0, 0]],
            'uncrop excessive margin' => ['uncrop', [$image, 4097, 0, 0, 0]],
            'uncrop conflicting size' => ['uncrop', [$image, 10, 0, 0, 0, ['size' => '16:9']]],
            'upscale invalid mode' => ['upscale', [$image, 2, ['mode' => 'unknown']]],
        ];
    }


    public function testCapabilities() : void
    {
        $provider = Prisma::image()->using( 'recraft', ['api_key' => 'test'] );

        foreach( ['background', 'erase', 'imagine', 'inpaint', 'isolate', 'repaint', 'uncrop', 'upscale'] as $method ) {
            $this->assertTrue( $provider->has( $method ), $method );
        }

        foreach( ['describe', 'detext', 'recognize', 'relocate', 'vectorize'] as $method ) {
            $this->assertFalse( $provider->has( $method ), $method );
        }
    }


    public function testMissingKey() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'No API key' );
        Prisma::image()->using( 'recraft', [] );
    }
}
