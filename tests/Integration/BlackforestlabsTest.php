<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;


class BlackforestlabsTest extends TestCase
{
    public function testImagine() : void
    {
        $config = ['api_key' => $_ENV['BLACKFORESTLABS_API_KEY']];
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $pending = Prisma::image()
            ->using( 'blackforestlabs', $config )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog', [$image] );

        // queued job: continue polling with a new provider instance
        $response = Prisma::image()->using( 'blackforestlabs', $config )->ensure( 'resume' )->resume( (string) $pending->jobId() );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/blackforestlabs_imagine.png', $response->binary() );
    }


    public function testInpaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );

        $response = Prisma::image()
            ->using( 'blackforestlabs', ['api_key' => $_ENV['BLACKFORESTLABS_API_KEY']])
            ->ensure( 'inpaint' )
            ->inpaint( $image, $mask, 'add eye glasses' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/blackforestlabs_inpaint.png', $response->binary() );
    }


    public function testRepaint() : void
    {
        $response = Prisma::image()->using( 'blackforestlabs', ['api_key' => $_ENV['BLACKFORESTLABS_API_KEY']] )
            ->ensure( 'repaint' )->repaint( Image::fromLocalPath( __DIR__ . '/assets/cat.png' ), 'Paint the cat in watercolor' );

        $this->assertNotEmpty( $response->binary() );
        $this->assertStringStartsWith( 'image/', $response->mimeType() );
    }


    public function testUncrop() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/photo.jpg' );
        $response = Prisma::image()
            ->using( 'blackforestlabs', ['api_key' => $_ENV['BLACKFORESTLABS_API_KEY']])
            ->ensure( 'uncrop' )
            ->uncrop( $image, 0, 200, 0, 0 );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/blackforestlabs_uncrop.png', $response->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['BLACKFORESTLABS_API_KEY'] ) ) {
            $this->markTestSkipped( 'BLACKFORESTLABS_API_KEY is not defined in the environment' );
        }
    }
}
