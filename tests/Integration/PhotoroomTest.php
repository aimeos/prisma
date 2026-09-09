<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class PhotoroomTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->safeLoad();

        if( empty( $_ENV['PHOTOROOM_API_KEY'] ) ) {
            $this->markTestSkipped( 'PHOTOROOM_API_KEY is not defined in the environment' );
        }
    }


    #[DataProvider('operations')]
    public function testImageOperation( string $method, array $arguments ) : void
    {
        $response = Prisma::image()->using( 'photoroom', ['api_key' => $_ENV['PHOTOROOM_API_KEY']] )
            ->ensure( $method )->$method( ...$arguments );

        $this->assertNotEmpty( $response->binary() );
        $this->assertStringStartsWith( 'image/', $response->mimeType() );
        $size = getimagesizefromstring( $response->binary() );
        $this->assertNotFalse( $size );

        if( $method === 'upscale' ) {
            $this->assertSame( [1280, 1280], [$size[0], $size[1]] );
        }
    }


    public static function operations() : array
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $background = Image::fromLocalPath( __DIR__ . '/assets/photo.jpg' );
        $text = Image::fromLocalPath( __DIR__ . '/assets/detext.jpg' );

        return [
            'background' => ['background', [$image, 'A forest']],
            'detext' => ['detext', [$text]],
            'imagine' => ['imagine', ['A cartoon fox in a forest']],
            'isolate' => ['isolate', [$image]],
            'relocate' => ['relocate', [$image, $background]],
            'repaint' => ['repaint', [$image, 'A watercolor painting']],
            'upscale' => ['upscale', [$image, 4]],
        ];
    }
}
