<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class VeoTest extends TestCase
{
    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'veo', ['api_key' => $_ENV['GEMINI_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['GEMINI_API_KEY'] ) ) {
            $this->markTestSkipped( 'GEMINI_API_KEY is not defined in the environment' );
        }
    }
}
