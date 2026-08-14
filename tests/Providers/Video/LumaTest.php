<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class LumaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyDropsReferencesInFrameMode() : void
    {
        $this->prisma( 'video', 'luma', ['api_key' => 'test'] )
            ->response( ['id' => 'generation-1'], [], 201 );
        $this->response( [
            'state' => 'completed',
            'output' => [['type' => 'video', 'url' => 'https://example.com/video.mp4']],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ], ['duration' => 10, 'resolution' => '1080p'] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( ['url' => 'https://example.com/start.png'], $body['video']['start_frame'] );
        $this->assertSame( ['url' => 'https://example.com/end.png'], $body['video']['end_frame'] );
        $this->assertSame( '10s', $body['video']['duration'] );
        $this->assertStringNotContainsString( 'reference.png', (string) $this->requests()[0]->getBody() );
    }


    public function testEndFrameWithoutStartIsIgnored() : void
    {
        $this->prisma( 'video', 'luma', ['api_key' => 'test'] )
            ->response( ['id' => 'generation-1'] );

        $this->provider()->imagine( 'prompt', [
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertArrayNotHasKey( 'start_frame', $body['video'] );
        $this->assertArrayNotHasKey( 'end_frame', $body['video'] );
    }
}
