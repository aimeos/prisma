<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MetaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'meta', [] );
    }


    public function testImagine() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';

        $response = $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( json_encode( [
                'created' => 1784584435,
                'data' => [['b64_json' => $base64]],
                'output_format' => 'png',
                'usage' => ['input_tokens' => 9, 'output_tokens' => 749, 'total_tokens' => 758],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], [
                'n' => 2,
                'size' => '1024x1536',
                'output_format' => 'png',
                'reasoning_strength' => 'low',
                'unknown' => 'x',
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/images/generations', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'muse-image-1.0', $body['model'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 2, $body['n'] );
            $this->assertEquals( '1024x1536', $body['size'] );
            $this->assertEquals( 'png', $body['output_format'] );
            $this->assertEquals( 'low', $body['reasoning_strength'] );
            $this->assertArrayNotHasKey( 'images', $body );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertCount( 1, $response->files() );
        $this->assertEquals( $base64, $response->base64() );
        $this->assertEquals( 758, $response->usage()['used'] );
        $this->assertEquals( 'png', $response->meta()['output_format'] );
    }


    public function testImagineError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );
    }


    public function testImagineFromUrl() : void
    {
        $response = $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => [['url' => 'https://example.com/fox.webp']],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['response_format' => 'url'] );

        $this->assertEquals( 'https://example.com/fox.webp', $response->first()?->url() );
    }


    public function testImagineInvalidReference() : void
    {
        $this->expectException( BadRequestException::class );

        $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => base64_encode( 'PNG' )]]] )
            ->imagine( 'a fox', ['not-an-image'] );
    }


    public function testImagineWithReferences() : void
    {
        $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => base64_encode( 'PNG' )]]] )
            ->ensure( 'imagine' )
            ->imagine( 'Combine both', [
                Image::fromBinary( 'PNG', 'image/png' ),
                Image::fromUrl( 'https://example.com/hat.png' ),
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/images/edits', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'muse-image-1.0', $body['model'] );
            $this->assertEquals( [
                ['image_url' => 'data:image/png;base64,' . base64_encode( 'PNG' )],
                ['image_url' => 'https://example.com/hat.png'],
            ], $body['images'] );
        } );
    }


    public function testRepaint() : void
    {
        $response = $this->prisma( 'image', 'meta', ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => base64_encode( 'PNG' )]]] )
            ->ensure( 'repaint' )
            ->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'Add a red hat', ['n' => 1, 'prompt' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/images/edits', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Add a red hat', $body['prompt'] );
            $this->assertEquals( 1, $body['n'] );
            $this->assertCount( 1, $body['images'] );
            $this->assertStringStartsWith( 'data:image/png;base64,', $body['images'][0]['image_url'] );
        } );

        $this->assertEquals( 'PNG', $response->binary() );
    }
}
