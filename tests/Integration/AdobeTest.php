<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class AdobeTest extends TestCase
{
    #[DataProvider('methods')]
    public function testImageMethod( string $method ) : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );
        $args = match( $method ) {
            'background' => [$image, 'A sunlit forest'],
            'imagine' => ['A watercolor painting of a cat'],
            'inpaint' => [$image, $mask, 'Add glasses'],
            'relocate' => [Image::fromLocalPath( __DIR__ . '/assets/product.png' ), $image, ['fillAreaMask' => $mask]],
            'repaint' => [$image, 'Paint the cat in watercolor'],
            'uncrop' => [$image, 32, 64, 32, 64],
            'upscale' => [$image, 2],
        };

        $response = Prisma::image()->using( 'adobe', [
            'api_key' => $_ENV['ADOBE_ACCESS_TOKEN'], 'client_id' => $_ENV['ADOBE_CLIENT_ID']
        ] )->ensure( $method )->$method( ...$args );

        $this->assertNotEmpty( $response->binary() );
        $this->assertStringStartsWith( 'image/', $response->mimeType() );
    }


    public static function methods() : array
    {
        return array_map( fn( $method ) => [$method], ['background', 'imagine', 'inpaint', 'relocate', 'repaint', 'uncrop', 'upscale'] );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->safeLoad();

        if( empty( $_ENV['ADOBE_ACCESS_TOKEN'] ) || empty( $_ENV['ADOBE_CLIENT_ID'] ) ) {
            $this->markTestSkipped( 'ADOBE_ACCESS_TOKEN and ADOBE_CLIENT_ID are required' );
        }
    }
}
