<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenrouterTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'A lighthouse']]],
                'usage' => ['total_tokens' => 8],
            ] )
            ->ensure( 'describe' )
            ->describe( Image::fromBinary( 'PNG', 'image/png' ), 'en' );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'google/gemini-3.7-flash', $body['model'] );
            $this->assertSame( 'image_url', $content[0]['type'] );
            $this->assertSame( 'data:image/png;base64,' . base64_encode( 'PNG' ), $content[0]['image_url']['url'] );
        } );

        $this->assertSame( 'A lighthouse', $response->text() );
    }


    public function testImagine() : void
    {
        $base64 = base64_encode( 'PNG DATA' );
        $response = $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'created' => 123,
                'data' => [['b64_json' => $base64, 'media_type' => 'image/png']],
                'usage' => ['total_tokens' => 20, 'cost' => 0.04],
            ] )
            ->ensure( 'imagine' )
            ->imagine( 'A red fox', [], [
                'aspectRatio' => '16:9',
                'quality' => 'high',
                'size' => '2K',
                'user' => 'customer-1',
                'unknown' => true,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );

            $this->assertSame( 'https://openrouter.ai/api/v1/images', (string) $request->getUri() );
            $this->assertSame( 'openai/gpt-image-2', $body['model'] );
            $this->assertSame( 'A red fox', $body['prompt'] );
            $this->assertSame( '16:9', $body['aspect_ratio'] );
            $this->assertSame( 'high', $body['quality'] );
            $this->assertSame( '2K', $body['size'] );
            $this->assertSame( 'customer-1', $body['user'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertSame( $base64, $response->base64() );
        $this->assertSame( 'image/png', $response->mimeType() );
        $this->assertSame( 20, $response->usage()->totalTokens() );
        $this->assertSame( 123, $response->meta()['created'] );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => base64_encode( 'EDITED' )]]] )
            ->ensure( 'repaint' )
            ->repaint(
                Image::fromUrl( 'https://example.com/source.png', 'image/png' ),
                'Make it a watercolor'
            );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );

            $this->assertSame( 'Make it a watercolor', $body['prompt'] );
            $this->assertSame( 'image_url', $body['input_references'][0]['type'] );
            $this->assertSame( 'https://example.com/source.png', $body['input_references'][0]['image_url']['url'] );
        } );
    }


    public function testRecognize() : void
    {
        $response = $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'Invoice 123']]]] )
            ->ensure( 'recognize' )
            ->recognize( Image::fromUrl( 'https://example.com/invoice.png', 'image/png' ) );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertStringContainsString( 'Extract all visible text', $content[1]['text'] );
        } );

        $this->assertSame( 'Invoice 123', $response->text() );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'data' => [['embedding' => [0.1, 0.2], 'index' => 0]],
                'model' => 'voyageai/voyage-multimodal-3.5',
                'usage' => ['total_tokens' => 9],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( [Image::fromUrl( 'https://example.com/cat.png', 'image/png' )], 1024 );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );

            $this->assertSame( 'https://openrouter.ai/api/v1/embeddings', (string) $request->getUri() );
            $this->assertSame( 'voyageai/voyage-multimodal-3.5', $body['model'] );
            $this->assertSame( 1024, $body['dimensions'] );
            $this->assertSame( 'image_url', $body['input'][0]['content'][0]['type'] );
            $this->assertSame( 'https://example.com/cat.png', $body['input'][0]['content'][0]['image_url']['url'] );
        } );

        $this->assertSame( [0.1, 0.2], $response->first() );
    }


    public function testImagineWithoutImageFails() : void
    {
        $this->prisma( 'image', 'openrouter', ['api_key' => 'test'] )
            ->response( ['data' => []] );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'No image data found in response' );

        $this->provider()->imagine( 'prompt' );
    }
}
