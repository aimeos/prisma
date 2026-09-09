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
            'repaint' => ['repaint', [$image, 'Winter'], 'imageToImage', $input + ['prompt' => 'Winter', 'model' => 'recraftv4_1', 'strength' => 0.5], false],
            'repaint strength' => ['repaint', [$image, 'Winter', ['strength' => 0, 'random_seed' => 12]], 'imageToImage', $input + ['strength' => 0, 'random_seed' => 12], false],
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


    public function testCapabilities() : void
    {
        $provider = Prisma::image()->using( 'recraft', ['api_key' => 'test'] );

        foreach( ['repaint'] as $method ) {
            $this->assertTrue( $provider->has( $method ), $method );
        }

        foreach( ['background', 'erase', 'imagine', 'inpaint', 'isolate', 'uncrop', 'upscale', 'describe', 'detext', 'recognize', 'relocate', 'vectorize'] as $method ) {
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
