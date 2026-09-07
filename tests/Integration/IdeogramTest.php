<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;


class IdeogramTest extends TestCase
{
    public function testDescribe() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $image );

        $this->assertNotEmpty( $response->text() );
        $this->assertNotEmpty( $response->structured() );
    }


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


    #[TestWith( [false] )]
    #[TestWith( [true] )]
    public function testImagine( bool $async ) : void
    {
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A watercolor painting of a cat sitting beside a vase of flowers', [], ['async' => $async] );

        if( $async ) {
            $this->assertNotEmpty( $response->meta()['generation_id'] );
        }

        $binary = (string) $response->binary();
        $this->assertGreaterThan( 0, strlen( $binary ) );
        $this->assertTrue( $response->ready() );

        if( $async ) {
            $this->assertSame( 'completed', $response->meta()['status'] );
        }

        file_put_contents( __DIR__ . '/results/ideogram_imagine' . ( $async ? '_async' : '' ) . '.png', $binary );
    }


    public function testIsolate() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'isolate' )
            ->isolate( $image );

        $this->assertTransparentPng( (string) $response->binary() );

        file_put_contents( __DIR__ . '/results/ideogram_isolate.png', $response->binary() );
    }


    public function testRepaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $image, 'Repaint the scene as a watercolor illustration' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/ideogram_repaint.png', $response->binary() );
    }


    #[TestWith( [false] )]
    #[TestWith( [true] )]
    public function testImagineTransparent( bool $async ) : void
    {
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->imagine( 'A watercolor sunflower on a transparent background', [], [
                'async' => $async,
                'transparent' => true,
                'aspect_ratio' => '1x1',
                'output_resolution' => '1K',
                'rendering_speed' => 'TURBO',
            ] );

        if( $async ) {
            $this->assertNotEmpty( $response->meta()['generation_id'] );
        }

        $binary = (string) $response->binary();
        $this->assertTransparentPng( $binary );
        $this->assertTrue( $response->ready() );

        if( $async ) {
            $this->assertSame( 'completed', $response->meta()['status'] );
        }

        file_put_contents( __DIR__ . '/results/ideogram_imagine_transparent' . ( $async ? '_async' : '' ) . '.png', $binary );
    }


    public function testRepaintTransparent() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'ideogram', ['api_key' => $_ENV['IDEOGRAM_API_KEY']] )
            ->repaint( $image, 'Turn the cat into a watercolor illustration on a transparent background', [
                'transparent' => true,
            ] );

        $binary = (string) $response->binary();
        $this->assertTransparentPng( $binary );

        file_put_contents( __DIR__ . '/results/ideogram_repaint_transparent.png', $binary );
    }


    private function assertTransparentPng( string $binary ) : void
    {
        $this->assertSame( 'image/png', ( new \finfo( FILEINFO_MIME_TYPE ) )->buffer( $binary ) );
        $image = imagecreatefromstring( $binary );
        $this->assertNotFalse( $image );
        $transparent = false;

        for( $y = 0; $y < imagesy( $image ); $y++ )
        {
            for( $x = 0; $x < imagesx( $image ); $x++ )
            {
                if( imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) )['alpha'] > 0 ) {
                    $transparent = true;
                    break 2;
                }
            }
        }

        $this->assertTrue( $transparent, 'The image must contain transparent pixels' );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['IDEOGRAM_API_KEY'] ) ) {
            $this->markTestSkipped( 'IDEOGRAM_API_KEY is not defined in the environment' );
        }
    }
}
