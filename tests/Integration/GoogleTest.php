<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class GoogleTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['GOOGLE_API_KEY'] ) ) {
            $this->markTestSkipped( 'GOOGLE_API_KEY is not defined in the environment' );
        }
    }


    public function testTranslate() : void
    {
        $response = Prisma::text()
            ->using( 'google', ['api_key' => $_ENV['GOOGLE_API_KEY']] )
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de' );

        $texts = $response->texts();

        $this->assertCount( 2, $texts );
        $this->assertStringContainsStringIgnoringCase( 'Hallo', $texts[0] );
    }
}
