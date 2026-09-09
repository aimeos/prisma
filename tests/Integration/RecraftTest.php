<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;


class RecraftTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->safeLoad();

        if( empty( $_ENV['RECRAFT_API_KEY'] ) ) {
            $this->markTestSkipped( 'RECRAFT_API_KEY is not defined in the environment' );
        }
    }


    #[DataProvider('operations')]
    public function testImageOperation( string $method, array $arguments ) : void
    {
        $response = Prisma::image()->using( 'recraft', ['api_key' => $_ENV['RECRAFT_API_KEY']] )
            ->ensure( $method )->$method( ...$arguments );

        $this->assertNotEmpty( $response->binary() );
        $this->assertStringStartsWith( 'image/', $response->mimeType() );
    }


    public static function operations() : array
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );
        $base64 = ['response_format' => 'b64_json'];

        return [
            'background' => ['background', [$image, 'A forest', $base64]],
            'masked background' => ['background', [$image, 'A forest', ['mask' => $mask] + $base64]],
            'erase' => ['erase', [$image, $mask, $base64]],
            'imagine' => ['imagine', ['A cartoon fox in a forest', [], $base64]],
            'style references' => ['imagine', ['A cartoon fox', [$image], $base64]],
            'inpaint' => ['inpaint', [$image, $mask, 'Glasses', $base64]],
            'isolate' => ['isolate', [$image, $base64]],
            'repaint' => ['repaint', [$image, 'A watercolor painting', $base64]],
            'uncrop' => ['uncrop', [$image, 0, 100, 0, 0, $base64]],
            'crisp upscale' => ['upscale', [$image, 2, $base64]],
            'creative upscale' => ['upscale', [$image, 2, ['mode' => 'creative'] + $base64]],
        ];
    }
}
