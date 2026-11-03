<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PaymentRequiredException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class KlingTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => ['id' => 'task-1', 'status' => 'submitted']] );
        $this->response( ['code' => 0, 'data' => [['id' => 'task-1', 'status' => 'succeeded', 'outputs' => [
            ['type' => 'video', 'url' => 'https://example.com/video.mp4'],
        ]]]] );

        $response = $this->provider()->imagine( 'prompt', [], [
            'duration' => 10, 'resolution' => '1080p', 'aspectRatio' => '9:16', 'audio' => true, 'seed' => 1,
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $this->assertSame( 'https://api-singapore.klingai.com/text-to-video/kling-3.0', (string) $this->requests()[0]->getUri() );
        $this->assertSame( 'Bearer test', $this->requests()[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( 'https://api-singapore.klingai.com/tasks?task_ids=task-1', (string) $this->requests()[1]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( [['type' => 'prompt', 'text' => 'prompt']], $body['contents'] );
        $this->assertSame( ['duration' => 10, 'resolution' => '1080p', 'aspect_ratio' => '9:16', 'audio' => 'native'], $body['settings'] );
    }


    public function testImagineFrames() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => ['id' => 'task-1']] );

        $this->provider()->model( 'kling-2.6' )->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromBinary( 'end', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ], ['aspectRatio' => '1:1'] );

        $this->assertSame( 'https://api-singapore.klingai.com/image-to-video/kling-2.6', (string) $this->requests()[0]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( [
            ['type' => 'prompt', 'text' => 'prompt'],
            ['type' => 'first_frame', 'url' => 'https://example.com/start.png'],
            ['type' => 'last_frame', 'url' => base64_encode( 'end' )],
        ], $body['contents'] );
        $this->assertArrayNotHasKey( 'settings', $body );
    }


    public function testImagineEndFrameOnly() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => ['id' => 'task-1']] );

        $this->provider()->imagine( 'prompt', ['end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' )] );

        $this->assertSame( 'https://api-singapore.klingai.com/text-to-video/kling-3.0', (string) $this->requests()[0]->getUri() );
    }


    public function testImagineReferences() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => ['id' => 'task-1']] );

        $images = array_map( fn( int $i ) => Image::fromUrl( 'https://example.com/' . $i . '.png', 'image/png' ), range( 1, 6 ) );
        $videos = [
            Video::fromUrl( 'https://example.com/1.mp4', 'video/mp4' ),
            Video::fromUrl( 'https://example.com/2.mp4', 'video/mp4' ),
        ];

        $this->provider()->imagine( 'prompt', ['references' => array_merge( $images, $videos )] );

        $this->assertSame( 'https://api-singapore.klingai.com/omni-video/kling-3.0-omni', (string) $this->requests()[0]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $types = array_count_values( array_column( $body['contents'], 'type' ) );
        $this->assertSame( ['prompt' => 1, 'refer_image' => 4, 'feature_video' => 1], $types );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => ['id' => 'task-1']] );

        $this->provider()->repaint( Video::fromUrl( 'https://example.com/video.mp4', 'video/mp4' ), 'prompt', [
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ], ['audio' => true, 'aspectRatio' => '1:1'] );

        $this->assertSame( 'https://api-singapore.klingai.com/omni-video/kling-3.0-omni', (string) $this->requests()[0]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( [
            ['type' => 'prompt', 'text' => 'prompt'],
            ['type' => 'base_video', 'url' => 'https://example.com/video.mp4'],
            ['type' => 'refer_image', 'url' => 'https://example.com/reference.png'],
        ], $body['contents'] );
        $this->assertSame( ['audio' => 'original'], $body['settings'] );
    }


    public function testPollFailed() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => [['id' => 'task-1', 'status' => 'failed', 'message' => 'risk control']]] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'risk control' );
        $this->provider()->resume( 'task-1' )->ready();
    }


    public function testPollNoVideo() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 0, 'data' => [['id' => 'task-1', 'status' => 'succeeded', 'outputs' => []]]] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( 'task-1' )->ready();
    }


    public function testPaymentRequired() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 1102, 'message' => 'resource pack exhausted'], [], 429 );

        $this->expectException( PaymentRequiredException::class );
        $this->expectExceptionMessage( 'resource pack exhausted' );
        $this->provider()->imagine( 'prompt' );
    }


    public function testRateLimited() : void
    {
        $this->prisma( 'video', 'kling', ['api_key' => 'test'] )
            ->response( ['code' => 1302, 'message' => 'too many requests'], ['Retry-After' => '10'], 429 );

        try {
            $this->provider()->imagine( 'prompt' );
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 'too many requests', $e->getMessage() );
            $this->assertSame( 10, $e->retryAfter() );
        }
    }
}
