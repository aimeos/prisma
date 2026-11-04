<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class FalTest extends TestCase
{
    use MakesPrismaRequests;

    private const URL = 'https://queue.fal.run/fal-ai/flux-2/requests/req-1';


    public function testImagine() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['request_id' => 'req-1', 'response_url' => self::URL, 'status' => 'IN_QUEUE'] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['images' => [['url' => 'https://v3.fal.media/files/a.png', 'content_type' => 'image/png']], 'seed' => 1] );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'a fox', [], ['image_size' => 'square_hd'] );

        $this->assertTrue( $response->ready() );
        $this->assertSame( self::URL, $response->jobId() );
        $this->assertSame( 'https://v3.fal.media/files/a.png', $response->first()?->url() );
        $this->assertSame( 'image/png', $response->first()?->mimeType() );
        $this->assertSame( 1, $response->meta()['seed'] );

        $requests = $this->requests();
        $this->assertSame( 'https://queue.fal.run/fal-ai/flux-2', (string) $requests[0]->getUri() );
        $this->assertSame( 'Key test', $requests[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( ['image_size' => 'square_hd', 'prompt' => 'a fox'], json_decode( (string) $requests[0]->getBody(), true ) );
        $this->assertSame( self::URL . '/status', (string) $requests[1]->getUri() );
        $this->assertSame( self::URL, (string) $requests[2]->getUri() );
    }


    public function testImagineImages() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->imagine( 'a fox', [
            Image::fromUrl( 'https://example.com/a.png', 'image/png' ),
            Image::fromBinary( 'b', 'image/png' ),
        ] );

        $request = $this->requests()[0];
        $this->assertSame( 'https://queue.fal.run/fal-ai/flux-2/edit', (string) $request->getUri() );
        $this->assertSame( [
            'prompt' => 'a fox',
            'image_urls' => ['https://example.com/a.png', 'data:image/png;base64,' . base64_encode( 'b' )],
        ], json_decode( (string) $request->getBody(), true ) );
    }


    public function testModel() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => 'https://queue.fal.run/fal-ai/nano-banana-pro/requests/req-1'] );

        $this->provider()->model( 'fal-ai/nano-banana-pro' )->imagine( 'a fox' );

        $this->assertSame( 'https://queue.fal.run/fal-ai/nano-banana-pro', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['background', 'fal-ai/bria/background/replace', ['image_url' => 'https://example.com/a.png', 'prompt' => 'beach']] )]
    #[TestWith( ['detext', 'fal-ai/image-editing/text-removal', ['image_url' => 'https://example.com/a.png']] )]
    #[TestWith( ['erase', 'fal-ai/bria/eraser', ['image_url' => 'https://example.com/a.png', 'mask_url' => 'https://example.com/m.png']] )]
    #[TestWith( ['inpaint', 'fal-ai/flux-pro/v1/fill', ['image_url' => 'https://example.com/a.png', 'mask_url' => 'https://example.com/m.png', 'prompt' => 'beach']] )]
    #[TestWith( ['isolate', 'fal-ai/birefnet/v2', ['image_url' => 'https://example.com/a.png']] )]
    #[TestWith( ['relocate', 'fal-ai/bria/background/replace', ['image_url' => 'https://example.com/a.png', 'ref_image_url' => 'https://example.com/m.png']] )]
    #[TestWith( ['repaint', 'fal-ai/flux-pro/kontext', ['image_url' => 'https://example.com/a.png', 'prompt' => 'beach']] )]
    #[TestWith( ['upscale', 'fal-ai/seedvr/upscale/image', ['image_url' => 'https://example.com/a.png', 'upscale_mode' => 'factor', 'upscale_factor' => 10]] )]
    public function testOperation( string $method, string $endpoint, array $expected ) : void
    {
        $image = Image::fromUrl( 'https://example.com/a.png', 'image/png' );
        $mask = Image::fromUrl( 'https://example.com/m.png', 'image/png' );

        $args = match( $method ) {
            'background', 'repaint' => [$image, 'beach'],
            'erase', 'relocate' => [$image, $mask],
            'inpaint' => [$image, $mask, 'beach'],
            'detext', 'isolate' => [$image],
            'upscale' => [$image, 20],
        };

        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( $method )->$method( ...$args );

        $request = $this->requests()[0];
        $this->assertSame( 'https://queue.fal.run/' . $endpoint, (string) $request->getUri() );
        $this->assertSame( $expected, json_decode( (string) $request->getBody(), true ) );
    }


    public function testDescribe() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['output' => 'Eine Katze', 'usage' => ['total_tokens' => 42, 'cost' => 0.001], 'id' => 'x'] );

        $response = $this->provider()->ensure( 'describe' )
            ->model( 'fal-ai/flux-2' )->describe( Image::fromBinary( 'i', 'image/png' ), 'de', ['temperature' => 0.2] );

        $this->assertSame( 'Eine Katze', $response->text() );
        $this->assertSame( 42.0, $response->usage()['used'] );
        $this->assertSame( 'x', $response->meta()['id'] );
        $this->assertFalse( isset( $response->meta()['output'] ) );

        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'https://queue.fal.run/openrouter/router/vision', (string) $request->getUri() );
        $this->assertSame( 'google/gemini-3.8-flash', $body['model'] );
        $this->assertStringContainsString( '"de"', $body['prompt'] );
        $this->assertSame( ['data:image/png;base64,' . base64_encode( 'i' )], $body['image_urls'] );
        $this->assertSame( 0.2, $body['temperature'] );
    }


    public function testRecognize() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['output' => 'Hello'] );

        $response = $this->provider()->ensure( 'recognize' )->model( 'fal-ai/nano-banana-pro' )
            ->recognize( Image::fromUrl( 'https://example.com/a.png' ), ['model' => 'anthropic/claude-sonnet-5'] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'Hello', $response->text() );
        $this->assertSame( 'anthropic/claude-sonnet-5', $body['model'] );
        $this->assertSame( ['https://example.com/a.png'], $body['image_urls'] );
    }


    public function testRecognizeNoText() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['error' => 'x'] );

        $this->expectException( FailedException::class );
        $this->provider()->recognize( Image::fromUrl( 'https://example.com/a.png' ) )->text();
    }


    public function testUncrop() : void
    {
        $image = imagecreatetruecolor( 20, 10 );
        ob_start();
        imagepng( $image );
        $binary = (string) ob_get_clean();

        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->uncrop( Image::fromBinary( $binary, 'image/png' ), 1, 2, 3, 4 );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'https://queue.fal.run/fal-ai/bria/expand', (string) $this->requests()[0]->getUri() );
        $this->assertSame( [26, 14], $body['canvas_size'] );
        $this->assertSame( [20, 10], $body['original_image_size'] );
        $this->assertSame( [4, 1], $body['original_image_location'] );
    }


    public function testUncropInvalidImage() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->uncrop( Image::fromBinary( 'invalid', 'image/png' ), 1, 1, 1, 1 );
    }


    public function testSingleImage() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( ['image' => ['url' => 'https://fal.media/files/a.png', 'content_type' => 'image/png']] );

        $response = $this->provider()->resume( self::URL );

        $this->assertTrue( $response->ready() );
        $this->assertSame( 'https://fal.media/files/a.png', $response->first()?->url() );
    }


    public function testPending() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'IN_PROGRESS'] );

        $response = $this->provider()->resume( self::URL );

        $this->assertFalse( $response->ready() );
        $this->assertSame( 'IN_PROGRESS', $response->meta()['status'] );
        $this->assertCount( 1, $this->requests() );
        $this->assertSame( 3, $response->retryAfter() );
    }


    public function testFailed() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED', 'error' => 'NSFW content detected'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'NSFW content detected' );
        $this->provider()->resume( self::URL )->ready();
    }


    public function testNoImages() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( ['images' => []] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( self::URL )->ready();
    }


    public function testNoResponseUrl() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['request_id' => 'req-1'] );

        $this->expectException( FailedException::class );
        $this->provider()->imagine( 'a fox' );
    }


    public function testCancel() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'CANCELLATION_REQUESTED'], [], 202 );

        $this->provider()->ensure( 'cancel' )->cancel( self::URL );

        $this->assertSame( 'PUT', $this->requests()[0]->getMethod() );
        $this->assertSame( self::URL . '/cancel', (string) $this->requests()[0]->getUri() );
    }


    public function testRecognizeBehindApiGateway() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test', 'url' => 'https://gateway.example/fal/'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->recognize( Image::fromBinary( 'i', 'image/png' ) );

        $this->assertSame( 'https://gateway.example/fal/openrouter/router/vision', (string) $this->requests()[0]->getUri() );
    }


    public function testRecognizePending() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );
        $this->response( ['status' => 'IN_PROGRESS'] );

        $response = $this->provider()->recognize( Image::fromUrl( 'https://example.com/a.png' ) );

        $this->assertFalse( $response->ready() );
        $this->assertNull( $response->jobId() );
        $this->assertSame( 1, $response->retryAfter() );
    }


    public function testResumeBehindApiGateway() : void
    {
        // fal returns response URLs of its queue host even if the requests are sent through a gateway
        $this->prisma( 'image', 'fal', ['api_key' => 'test', 'url' => 'https://gateway.example/fal/'] )
            ->response( ['status' => 'IN_QUEUE'] );

        $this->assertFalse( $this->provider()->resume( self::URL )->ready() );

        $this->assertSame( self::URL . '/status', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( [''] )]
    #[TestWith( ['req-1'] )]
    #[TestWith( ['https://attacker.example/fal-ai/flux-2/requests/req-1'] )]
    #[TestWith( ['https://queue.fal.run.attacker.example/fal-ai/flux-2/requests/req-1'] )]
    #[TestWith( ['http://queue.fal.run/fal-ai/flux-2/requests/req-1'] )]
    #[TestWith( ['https://rest.fal.ai/fal-ai/flux-2/requests/req-1'] )]
    #[TestWith( ['https://queue.fal.run/fal-ai/flux-2'] )]
    #[TestWith( ['https://queue.fal.run/fal-ai/flux-2/requests/req-1/status'] )]
    #[TestWith( ['https://queue.fal.run/fal-ai/flux-2/requests/req-1/cancel'] )]
    public function testRejectsForeignUrl( string $jobId ) : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] );

        try {
            $this->provider()->resume( $jobId );
            $this->fail( 'BadRequestException expected for resume' );
        } catch( BadRequestException $e ) {
        }

        $this->expectException( BadRequestException::class );
        $this->provider()->cancel( $jobId );
    }


    public function testRestrictsDownloadHosts() : void
    {
        $this->prisma( 'image', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( ['images' => [['url' => 'https://attacker.example/a.png']]] );

        $response = $this->provider()->resume( self::URL );

        $this->expectException( \Throwable::class );
        $response->first()?->binary();
    }
}
