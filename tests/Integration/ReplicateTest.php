<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class ReplicateTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['REPLICATE_API_KEY'] ) ) {
            $this->markTestSkipped( 'REPLICATE_API_KEY is not defined in the environment' );
        }
    }


    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'replicate', ['api_key' => $_ENV['REPLICATE_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/replicate_imagine.png', $response->binary() );
    }
}
