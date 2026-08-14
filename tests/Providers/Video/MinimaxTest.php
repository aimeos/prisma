<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MinimaxTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyDropsReferencesWhenFramesAreUsed() : void
    {
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['task_id' => 'task-1'] );
        $this->response( ['status' => 'Success', 'file_id' => 42] );
        $this->response( ['file' => ['download_url' => 'https://example.com/video.mp4']] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'MiniMax-Hailuo-02', $body['model'] );
        $this->assertArrayNotHasKey( 'subject_reference', $body );
        $this->assertStringNotContainsString( 'reference.png', (string) $this->requests()[0]->getBody() );
    }


    public function testReferenceImagesSelectSubjectModel() : void
    {
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['task_id' => 'task-1'] );

        $this->provider()->imagine( 'prompt', [
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'S2V-01', $body['model'] );
        $this->assertSame( ['https://example.com/reference.png'], $body['subject_reference'][0]['image'] );
    }
}
