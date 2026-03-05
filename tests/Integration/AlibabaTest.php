<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;


class AlibabaTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['ALIBABA_API_KEY'] ) ) {
            $this->markTestSkipped( 'ALIBABA_API_KEY is not defined in the environment' );
        }
    }


    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/qwen_imagine.png', $response->binary() );
    }
}
