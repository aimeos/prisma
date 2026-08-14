<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class RunwayTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyDropsReferences() : void
    {
        $this->prisma( 'video', 'runway', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'], [], 201 );
        $this->response( [
            'status' => 'SUCCEEDED',
            'output' => ['https://example.com/video.mp4'],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ], ['aspectRatio' => '1:1'] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'https://api.dev.runwayml.com/v1/image_to_video', (string) $request->getUri() );
        $this->assertSame( '960:960', $body['ratio'] );
        $this->assertCount( 2, $body['promptImage'] );
        $this->assertStringNotContainsString( 'reference.png', (string) $request->getBody() );
    }


    public function testTextModeNormalizesUnsupportedAspectRatio() : void
    {
        $this->prisma( 'video', 'runway', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'] );

        $this->provider()->imagine( 'prompt', [], ['aspectRatio' => '1:1'] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( '1280:720', $body['ratio'] );
    }
}
