<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class LumaTest extends TestCase
{
    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'luma', ['api_key' => $_ENV['LUMA_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testRepaintVideo() : void
    {
        $source = Video::fromUrl( 'https://data.x.ai/docs/video-generation/portrait-wave.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'luma', ['api_key' => $_ENV['LUMA_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $source, 'Turn the scene into a watercolor painting' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['LUMA_API_KEY'] ) ) {
            $this->markTestSkipped( 'LUMA_API_KEY is not defined in the environment' );
        }
    }
}
