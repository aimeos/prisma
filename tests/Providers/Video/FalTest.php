<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class FalTest extends TestCase
{
    use MakesPrismaRequests;

    private const URL = 'https://queue.fal.run/fal-ai/kling-video/requests/req-1';


    public function testImagine() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['request_id' => 'req-1', 'response_url' => self::URL, 'status' => 'IN_QUEUE'] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['video' => ['url' => 'https://v3b.fal.media/files/v.mp4', 'content_type' => 'video/mp4']] );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'A fox', [], [
            'duration' => 10,
            'aspectRatio' => '9:16',
            'audio' => true,
            'shot_type' => 'intelligent',
        ] );

        $this->assertTrue( $response->ready() );
        $this->assertSame( self::URL, $response->jobId() );
        $this->assertInstanceOf( Video::class, $response->first() );
        $this->assertSame( 'https://v3b.fal.media/files/v.mp4', $response->first()?->url() );
        $this->assertSame( 'video/mp4', $response->first()?->mimeType() );
        $this->assertFalse( isset( $response->meta()['video'] ) );

        $requests = $this->requests();
        $this->assertSame( 'https://queue.fal.run/fal-ai/kling-video/o3/pro/text-to-video', (string) $requests[0]->getUri() );
        $this->assertSame( 'Key test', $requests[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( [
            'duration' => '10',
            'aspectRatio' => '9:16',
            'audio' => true,
            'shot_type' => 'intelligent',
            'aspect_ratio' => '9:16',
            'generate_audio' => true,
            'prompt' => 'A fox',
        ], json_decode( (string) $requests[0]->getBody(), true ) );
        $this->assertSame( self::URL . '/status', (string) $requests[1]->getUri() );
        $this->assertSame( self::URL, (string) $requests[2]->getUri() );
    }


    public function testImagineFrames() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->imagine( 'A fox', [
            'start' => Image::fromUrl( 'https://example.com/start.png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png' ),
        ] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/kling-video/o3/pro/image-to-video', (string) $this->requests()[0]->getUri() );
        $this->assertSame( [
            'prompt' => 'A fox',
            'image_url' => 'https://example.com/start.png',
            'end_image_url' => 'https://example.com/end.png',
        ], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testImagineEndOnly() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->imagine( 'A fox', ['end' => Image::fromUrl( 'https://example.com/end.png' )] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/kling-video/o3/pro/text-to-video', (string) $this->requests()[0]->getUri() );
        $this->assertSame( ['prompt' => 'A fox'], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testImagineReferences() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $references = array_fill( 0, 8, Image::fromBinary( 'i', 'image/png' ) );
        $references[] = Audio::fromUrl( 'https://example.com/a.mp3' );

        $this->provider()->imagine( 'A fox', [
            'start' => Image::fromUrl( 'https://example.com/start.png' ),
            'references' => $references,
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'https://queue.fal.run/fal-ai/kling-video/o3/pro/reference-to-video', (string) $this->requests()[0]->getUri() );
        $this->assertSame( 'https://example.com/start.png', $body['start_image_url'] );
        $this->assertSame( array_fill( 0, 7, 'data:image/png;base64,' . base64_encode( 'i' ) ), $body['image_urls'] );
        $this->assertArrayNotHasKey( 'image_url', $body );
    }


    public function testModel() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => 'https://queue.fal.run/fal-ai/veo3.1/requests/req-1'] );

        $this->provider()->model( 'fal-ai/veo3.1' )->imagine( 'A fox', [], ['duration' => '8s'] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/veo3.1', (string) $this->requests()[0]->getUri() );
        $this->assertSame( ['duration' => '8s', 'prompt' => 'A fox'], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'repaint' )->repaint(
            Video::fromUrl( 'https://example.com/v.mp4', 'video/mp4' ),
            'Make it snow',
            ['references' => array_fill( 0, 5, Image::fromUrl( 'https://example.com/i.png' ) )],
            ['audio' => false]
        );

        $this->assertSame( 'https://queue.fal.run/fal-ai/kling-video/o3/pro/video-to-video/edit', (string) $this->requests()[0]->getUri() );
        $this->assertSame( [
            'audio' => false,
            'video_url' => 'https://example.com/v.mp4',
            'prompt' => 'Make it snow',
            'image_urls' => array_fill( 0, 4, 'https://example.com/i.png' ),
            'keep_audio' => false,
        ], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testRepaintLegacyOptions() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->repaint( Video::fromUrl( 'https://example.com/v.mp4' ), 'Make it snow', ['shot_type' => 'customize'] );

        $this->assertSame(
            ['shot_type' => 'customize', 'video_url' => 'https://example.com/v.mp4', 'prompt' => 'Make it snow'],
            json_decode( (string) $this->requests()[0]->getBody(), true )
        );
    }


    public function testDescribe() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['output' => 'A flower opens', 'usage' => ['total_tokens' => 30]] );

        // model() selects the queue endpoint only, the LLM is passed as option
        $response = $this->provider()->ensure( 'describe' )->model( 'fal-ai/veo3.1' )
            ->describe( Video::fromUrl( 'https://example.com/v.mp4' ), null, ['model' => 'google/gemini-2.5-pro'] );

        $this->assertSame( 'A flower opens', $response->text() );

        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'https://fal.run/openrouter/router/video', (string) $request->getUri() );
        $this->assertSame( 'google/gemini-2.5-pro', $body['model'] );
        $this->assertSame( ['https://example.com/v.mp4'], $body['video_urls'] );
        $this->assertStringContainsString( '"en"', $body['prompt'] );
    }


    public function testExtend() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'extend' )->extend( Video::fromBinary( 'v', 'video/mp4' ), 'The camera pulls back', ['generate_audio' => false] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/veo3.1/extend-video', (string) $this->requests()[0]->getUri() );
        $this->assertSame( [
            'generate_audio' => false,
            'video_url' => 'data:video/mp4;base64,' . base64_encode( 'v' ),
            'prompt' => 'The camera pulls back',
        ], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testUncrop() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'uncrop' )->uncrop( Video::fromUrl( 'https://example.com/v.mp4' ), 'A beach', 0, 0.5, -1, 0.25 );

        $this->assertSame( 'https://queue.fal.run/fal-ai/wan-vace-14b/outpainting', (string) $this->requests()[0]->getUri() );
        $this->assertSame( [
            'video_url' => 'https://example.com/v.mp4',
            'prompt' => 'A beach',
            'expand_top' => false,
            'expand_right' => true,
            'expand_bottom' => false,
            'expand_left' => true,
            'expand_ratio' => 0.5,
        ], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testUncropNoExpansion() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->uncrop( Video::fromUrl( 'https://example.com/v.mp4' ), 'A beach', 0, 0, 0, 0 );
    }


    #[TestWith( [2, 2] )]
    #[TestWith( [20, 10] )]
    public function testUpscale( int $factor, int $expected ) : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'upscale' )->upscale( Video::fromUrl( 'https://example.com/v.mp4' ), $factor );

        $this->assertSame( 'https://queue.fal.run/fal-ai/seedvr/upscale/video', (string) $this->requests()[0]->getUri() );
        $this->assertSame(
            ['video_url' => 'https://example.com/v.mp4', 'upscale_mode' => 'factor', 'upscale_factor' => $expected],
            json_decode( (string) $this->requests()[0]->getBody(), true )
        );
    }


    public function testForeignDownloadHost() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( ['video' => ['url' => 'https://attacker.example/v.mp4']] );

        $response = $this->provider()->resume( self::URL );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Invalid or unsafe URL' );
        $response->first()?->binary();
    }


    public function testFailed() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED', 'error' => 'Content policy violation'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Content policy violation' );
        $this->provider()->resume( self::URL )->ready();
    }


    public function testPending() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'IN_PROGRESS', 'queue_position' => 0] );

        $response = $this->provider()->ensure( 'resume' )->resume( self::URL );

        $this->assertFalse( $response->ready() );
        $this->assertSame( 'IN_PROGRESS', $response->meta()['status'] );
        $this->assertSame( 10, $response->retryAfter() );
    }


    public function testCancel() : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'CANCELLATION_REQUESTED'], [], 202 );

        $this->provider()->ensure( 'cancel' )->cancel( self::URL );

        $this->assertSame( 'PUT', $this->requests()[0]->getMethod() );
        $this->assertSame( self::URL . '/cancel', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( [''] )]
    #[TestWith( ['https://attacker.example/fal-ai/kling-video/requests/req-1'] )]
    #[TestWith( ['https://queue.fal.run/fal-ai/kling-video/requests/req-1/status'] )]
    public function testRejectsForeignUrl( string $jobId ) : void
    {
        $this->prisma( 'video', 'fal', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( $jobId );
    }
}
