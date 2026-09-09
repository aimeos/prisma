<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class StabilityaiTest extends TestCase
{
    #[DataProvider('operations')]
    public function testImageOperation( string $method, array $arguments ) : void
    {
        $response = Prisma::image()
            ->using( 'stabilityai', ['api_key' => $_ENV['STABILITYAI_API_KEY']])
            ->ensure( $method )
            ->$method( ...$arguments );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/stabilityai_' . $method . '.png', $response->binary() );
    }


    public static function operations() : array
    {
        return [
            'erase' => ['erase', [Image::fromLocalPath( __DIR__ . '/assets/photo.jpg' ), Image::fromLocalPath( __DIR__ . '/assets/mask-erase.png' )]],
            'imagine' => ['imagine', ['a cartoon dog', [Image::fromLocalPath( __DIR__ . '/assets/cat.png' )]]],
            'inpaint' => ['inpaint', [Image::fromLocalPath( __DIR__ . '/assets/cat.png' ), Image::fromLocalPath( __DIR__ . '/assets/mask.png' ), 'add eye glasses']],
            'isolate' => ['isolate', [Image::fromLocalPath( __DIR__ . '/assets/cat.png' )]],
            'uncrop' => ['uncrop', [Image::fromLocalPath( __DIR__ . '/assets/photo.jpg' ), 0, 200, 0, 0]],
            'upscale' => ['upscale', [Image::fromLocalPath( __DIR__ . '/assets/cat.png' ), 2]],
        ];
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['STABILITYAI_API_KEY'] ) ) {
            $this->markTestSkipped( 'STABILITYAI_API_KEY is not defined in the environment' );
        }
    }
}
