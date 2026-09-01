<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class RunwayTest extends TestCase
{
    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'runway', ['api_key' => $_ENV['RUNWAY_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testRepaintVideo() : void
    {
        $source = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $reference = Image::fromLocalPath( __DIR__ . '/assets/photo.jpg', 'image/jpeg' );
        $response = Prisma::video()
            ->using( 'runway', ['api_key' => $_ENV['RUNWAY_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $source, 'Repaint the scene using the reference image as visual guidance', [
                'references' => [$reference],
            ] );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testUpscaleVideo() : void
    {
        $source = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'runway', ['api_key' => $_ENV['RUNWAY_API_KEY']] )
            ->ensure( 'upscale' )
            ->upscale( $source, 2, ['resolution' => '1k'] );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['RUNWAY_API_KEY'] ) ) {
            $this->markTestSkipped( 'RUNWAY_API_KEY is not defined in the environment' );
        }
    }
}
