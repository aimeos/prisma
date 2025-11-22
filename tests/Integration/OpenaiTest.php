<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;


class OpenaiTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['OPENAI_API_KEY'] ) ) {
            $this->markTestSkipped( 'OPENAI_API_KEY is not defined in the environment' );
        }
   }


    public function testDescribe() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $image );

        $this->assertStringContainsString( 'cartoon', $response->text() );
        $this->assertStringContainsString( 'cat', $response->text() );
    }


    public function testImagine() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog', [$image] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/openai_imagine.png', $response->binary() );
    }


    public function testInpaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );

        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'inpaint' )
            ->inpaint( $image, $mask, 'add glasses' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/openai_inpaint.png', $response->binary() );
    }
}
