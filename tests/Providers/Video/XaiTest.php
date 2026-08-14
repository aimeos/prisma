<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class XaiTest extends TestCase
{
    use MakesPrismaRequests;


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
}
