<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class FalTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->safeLoad();

        if( empty( $_ENV['FAL_API_KEY'] ) ) {
            $this->markTestSkipped( 'FAL_API_KEY is not defined in the environment' );
        }
    }


    #[DataProvider('audioOperations')]
    public function testAudioOperation( string $method, array $arguments ) : void
    {
        $response = Prisma::audio()->using( 'fal', ['api_key' => $_ENV['FAL_API_KEY']] )
            ->ensure( $method )->$method( ...$arguments );

        $this->assertNotEmpty( $response->binary() );

        file_put_contents( __DIR__ . '/results/fal_' . $method . '.mp3', $response->binary() );
    }


    #[DataProvider('imageOperations')]
    public function testImageOperation( string $method, array $arguments ) : void
    {
        $response = Prisma::image()->using( 'fal', ['api_key' => $_ENV['FAL_API_KEY']] )
            ->ensure( $method )->$method( ...$arguments );

        $this->assertNotEmpty( $response->binary() );
        $this->assertNotFalse( getimagesizefromstring( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/fal_' . $method . '.png', $response->binary() );
    }


    public static function audioOperations() : array
    {
        $speech = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $music = Audio::fromLocalPath( __DIR__ . '/assets/musicfox.mp3' );

        return [
            'compose' => ['compose', ['Calm piano with soft strings', ['duration' => 10]]],
            'demix' => ['demix', [$music, 4]],
            'denoise' => ['denoise', [$speech]],
            'revoice' => ['revoice', [$speech, 'Rachel']],
            'speak' => ['speak', ['Hello world']],
            'synthesize' => ['synthesize', ['Thunder rumbling in the distance', ['duration' => 3]]],
        ];
    }


    public static function imageOperations() : array
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );
        $erase = Image::fromLocalPath( __DIR__ . '/assets/mask-erase.png' );

        return [
            'background' => ['background', [$image, 'A forest']],
            'erase' => ['erase', [$image, $erase]],
            'imagine' => ['imagine', ['A cartoon fox in a forest']],
            'inpaint' => ['inpaint', [$image, $mask, 'A red hat']],
            'isolate' => ['isolate', [$image]],
            'repaint' => ['repaint', [$image, 'A watercolor painting']],
            'uncrop' => ['uncrop', [$image, 100, 100, 100, 100]],
            'upscale' => ['upscale', [$image, 2]],
        ];
    }
}
