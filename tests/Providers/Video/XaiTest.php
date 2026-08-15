<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class XaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testExtend() : void
    {
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )
            ->response( ['request_id' => 'video-1'] );
        $this->response( [
            'status' => 'done',
            'video' => ['url' => 'https://example.com/extended.mp4'],
        ] );

        $response = $this->provider()
            ->ensure( 'extend' )
            ->extend( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Reveal the city skyline', [
                'duration' => 6,
                'storage_options' => ['filename' => 'extended.mp4'],
                'aspectRatio' => '9:16',
            ] );

        $this->assertSame( 'https://example.com/extended.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://api.x.ai/v1/videos/extensions', (string) $request->getUri() );
        $this->assertSame( 'grok-imagine-video', $body['model'] );
        $this->assertSame( 'https://example.com/input.mp4', $body['video']['url'] );
        $this->assertSame( 'Reveal the city skyline', $body['prompt'] );
        $this->assertSame( 6, $body['duration'] );
        $this->assertSame( ['filename' => 'extended.mp4'], $body['storage_options'] );
        $this->assertArrayNotHasKey( 'aspectRatio', $body );
    }


    public function testImagineSilentlyUsesStartInsteadOfReferences() : void
    {
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )
            ->response( ['request_id' => 'video-1'] );
        $this->response( [
            'status' => 'done',
            'video' => ['url' => 'https://example.com/video.mp4'],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'https://example.com/start.png', $body['image']['url'] );
        $this->assertArrayNotHasKey( 'reference_images', $body );
        $this->assertStringNotContainsString( 'end.png', (string) $this->requests()[0]->getBody() );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )
            ->response( ['request_id' => 'video-1'] );
        $this->response( [
            'status' => 'done',
            'video' => ['url' => 'https://example.com/repainted.mp4'],
        ] );

        $response = $this->provider()
            ->ensure( 'repaint' )
            ->repaint( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Add falling snow', [
                'storage_options' => ['filename' => 'snow.mp4'],
                'duration' => 5,
            ] );

        $this->assertSame( 'https://example.com/repainted.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://api.x.ai/v1/videos/edits', (string) $request->getUri() );
        $this->assertSame( 'grok-imagine-video', $body['model'] );
        $this->assertSame( 'https://example.com/input.mp4', $body['video']['url'] );
        $this->assertSame( 'Add falling snow', $body['prompt'] );
        $this->assertSame( ['filename' => 'snow.mp4'], $body['storage_options'] );
        $this->assertArrayNotHasKey( 'duration', $body );
    }
}
