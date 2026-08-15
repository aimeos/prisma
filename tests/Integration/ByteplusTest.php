<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class ByteplusTest extends TestCase
{
    public function testDescribeVideo() : void
    {
        $video = Video::fromLocalPath( __DIR__ . '/assets/pen.mp4' );
        $response = Prisma::video()
            ->using( 'byteplus', ['api_key' => $_ENV['BYTEPLUS_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $video );

        $this->assertStringContainsStringIgnoringCase( 'pen', $response->text() );
    }


    public function testExtendVideo() : void
    {
        $source = Video::fromUrl(
            'https://ark-doc.tos-ap-southeast-1.bytepluses.com/doc_video/r2v_extend_video1.mp4',
            'video/mp4'
        );
        $response = Prisma::video()
            ->using( 'byteplus', ['api_key' => $_ENV['BYTEPLUS_API_KEY']] )
            ->ensure( 'extend' )
            ->extend( $source, 'The arched window opens into an art museum', ['duration' => 8] );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'byteplus', ['api_key' => $_ENV['BYTEPLUS_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testRepaintVideo() : void
    {
        $source = Video::fromUrl(
            'https://ark-doc.tos-ap-southeast-1.bytepluses.com/doc_video/video_by_sd2.mp4',
            'video/mp4'
        );
        $response = Prisma::video()
            ->using( 'byteplus', ['api_key' => $_ENV['BYTEPLUS_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $source, 'Change the color of the cream to white' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['BYTEPLUS_API_KEY'] ) ) {
            $this->markTestSkipped( 'BYTEPLUS_API_KEY is not defined in the environment' );
        }
    }
}
