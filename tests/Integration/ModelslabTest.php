<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class ModelslabTest extends TestCase
{
    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'modelslab', ['api_key' => $_ENV['MODELSLAB_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/modelslab_imagine.png', $response->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['MODELSLAB_API_KEY'] ) ) {
            $this->markTestSkipped( 'MODELSLAB_API_KEY is not defined in the environment' );
        }
    }
}
