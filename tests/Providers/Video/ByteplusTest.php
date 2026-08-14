<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ByteplusTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyDropsReferencesWhenFramesAreUsed() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'], [], 201 );
        $this->response( [
            'status' => 'succeeded',
            'content' => ['video_url' => 'https://example.com/video.mp4'],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [
                Image::fromUrl( 'https://example.com/reference.png', 'image/png' ),
                Audio::fromUrl( 'https://example.com/reference.mp3', 'audio/mpeg' ),
                Video::fromUrl( 'https://example.com/reference.mp4', 'video/mp4' ),
            ],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertCount( 3, $body['content'] );
        $this->assertSame( ['first_frame', 'last_frame'], array_column( array_slice( $body['content'], 1 ), 'role' ) );
        $this->assertStringNotContainsString( 'reference.', (string) $this->requests()[0]->getBody() );
    }


    public function testAudioOnlyReferencesAreIgnored() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'] );

        $this->provider()->imagine( 'prompt', [
            'references' => [Audio::fromUrl( 'https://example.com/reference.mp3', 'audio/mpeg' )],
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertCount( 1, $body['content'] );
    }
}
