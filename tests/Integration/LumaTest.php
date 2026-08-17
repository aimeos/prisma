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
        $source = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'luma', ['api_key' => $_ENV['LUMA_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $source, 'Turn the flowers into a watercolor painting' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testUncropVideo() : void
    {
        $source = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'luma', ['api_key' => $_ENV['LUMA_API_KEY']] )
            ->ensure( 'uncrop' )
            ->uncrop( $source, 'Extend the flower garden naturally beyond the frame', 0, 0.25, 0, 0.25, [
                'resolution' => '720p',
            ] );

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
