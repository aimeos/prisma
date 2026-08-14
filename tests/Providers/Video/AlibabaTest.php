<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AlibabaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyFiltersFrameModeReferences() : void
    {
        $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( ['output' => ['task_id' => 'task-1']], [], 202 );
        $this->response( [
            'output' => [
                'task_status' => 'SUCCEEDED',
                'video_url' => 'https://example.com/video.mp4',
            ],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [
                Image::fromUrl( 'https://example.com/reference.png', 'image/png' ),
                Video::fromUrl( 'https://example.com/reference.mp4', 'video/mp4' ),
                Audio::fromUrl( 'https://example.com/driving.mp3', 'audio/mpeg' ),
                Audio::fromUrl( 'https://example.com/discarded.mp3', 'audio/mpeg' ),
            ],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'enable', $request->getHeaderLine( 'X-DashScope-Async' ) );
        $this->assertSame( 'wan2.7-i2v', $body['model'] );
        $this->assertSame( ['first_frame', 'last_frame', 'driving_audio'], array_column( $body['input']['media'], 'type' ) );
        $this->assertStringNotContainsString( 'reference.', (string) $request->getBody() );
        $this->assertStringNotContainsString( 'discarded.mp3', (string) $request->getBody() );
    }
}
