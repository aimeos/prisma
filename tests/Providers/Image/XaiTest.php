<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class XaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';

        $response = $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => [['b64_json' => $base64, 'revised_prompt' => 'a red fox']],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['n' => 2, 'response_format' => 'b64_json', 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.x.ai/v1/images/generations', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'grok-imagine-image-quality', $body['model'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 2, $body['n'] );
            $this->assertEquals( 'b64_json', $body['response_format'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertCount( 1, $response->files() );
        $this->assertEquals( $base64, $response->base64() );
    }


    public function testImagineError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );
    }


    public function testImagineFromUrl() : void
    {
        $response = $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => [['url' => 'https://example.com/fox.png']],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );

        $this->assertEquals( 'https://example.com/fox.png', $response->first()?->url() );
    }


    #[DataProvider('edits')]
    public function testEditing( string $method, array $images, ?string $model ) : void
    {
        $mock = $this->prisma( 'image', 'xai', ['api_key' => 'test'] );
        $provider = $mock->response( ['data' => [['b64_json' => base64_encode( 'PNG' )]], 'usage' => ['cost_in_usd_ticks' => 12], 'id' => 'edit'] )
            ->model( $model )->ensure( $method );
        $options = ['aspect_ratio' => '1:1', 'response_format' => 'b64_json', 'n' => 1, 'quality' => 'low', 'unknown' => true, 'prompt' => 'ignored', 'image' => 'ignored'];
        $response = $method === 'repaint' ? $provider->repaint( $images[0], 'Winter', $options ) : $provider->imagine( 'Winter', $images, $options );

        $this->assertPrismaRequest( function( $request ) use ( $images, $model ) {
            $this->assertSame( 'https://api.x.ai/v1/images/edits', (string) $request->getUri() );
            $this->assertSame( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $body = json_decode( (string) $request->getBody(), true );
            $this->assertSame( $model ?? 'grok-imagine-image-2.0', $body['model'] );
            $this->assertSame( 'Winter', $body['prompt'] );
            $this->assertSame( '1:1', $body['aspect_ratio'] );
            $this->assertSame( 'low', $body['quality'] );
            $this->assertSame( 'b64_json', $body['response_format'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
            $sources = count( $images ) === 1 ? [$body['image']] : $body['images'];
            $this->assertArrayNotHasKey( count( $images ) === 1 ? 'images' : 'image', $body );
            $this->assertCount( count( $images ), $sources );
            foreach( array_values( $images ) as $index => $image ) {
                $this->assertSame( ['type' => 'image_url', 'url' => $image->url() ?? 'data:image/png;base64,' . base64_encode( 'PNG' )], $sources[$index] );
            }
        } );

        $this->assertSame( 'PNG', $response->binary() );
        $this->assertSame( 12, $response->usage()['cost_in_usd_ticks'] );
        $this->assertSame( ['id' => 'edit'], $response->meta()->all() );
    }


    public static function edits() : array
    {
        $url = Image::fromUrl( 'https://example.com/source.png' );
        $binary = Image::fromBinary( 'PNG', 'image/png' );
        return [
            'repaint URL' => ['repaint', [$url], null],
            'repaint binary' => ['repaint', [$binary], 'grok-imagine-image-quality'],
            'single reference' => ['imagine', [$url], null],
            'mixed references' => ['imagine', [2 => $url, 4 => $binary], null],
            'five references' => ['imagine', array_fill( 0, 5, $url ), null],
        ];
    }


    #[DataProvider('invalidReferences')]
    public function testInvalidReferences( array $images ) : void
    {
        $this->expectException( BadRequestException::class );
        try {
            $this->prisma( 'image', 'xai', ['api_key' => 'test'] )->provider()->imagine( 'Winter', $images );
        } finally {
            $this->assertCount( 0, $this->requests() );
        }
    }


    public static function invalidReferences() : array
    {
        return [
            'invalid type' => [['bad']],
            'too many' => [array_fill( 0, 6, Image::fromUrl( 'https://example.com/source.png' ) )],
        ];
    }


    public function testRepaintError() : void
    {
        $this->expectException( BadRequestException::class );
        $this->expectExceptionMessage( 'Invalid source' );
        $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Invalid source']], status: 400 )
            ->repaint( Image::fromUrl( 'https://example.com/source.png' ), 'Winter' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'xai', [] );
    }
}
