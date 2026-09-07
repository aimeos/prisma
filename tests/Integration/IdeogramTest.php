<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class IdeogramTest extends TestCase
{
    public function testDetext() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/detext.jpg' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'detext' )
            ->detext( $image );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/ideogram_detext.png', $response->binary() );
    }


    public function testErase() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/photo.jpg' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask-erase.png' );

        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'erase' )
            ->erase( $image, $mask );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/ideogram_erase.png', $response->binary() );
    }


    public function testIsolate() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'isolate' )
            ->isolate( $image );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/ideogram_isolate.png', $response->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['IDEOGRAM_API_KEY'] ) ) {
            $this->markTestSkipped( 'IDEOGRAM_API_KEY is not defined in the environment' );
        }
    }
}
