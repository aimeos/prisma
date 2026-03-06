<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class DeeplTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['DEEPL_API_KEY'] ) ) {
            $this->markTestSkipped( 'DEEPL_API_KEY is not defined in the environment' );
        }
    }


    public function testTranslate() : void
    {
        $response = Prisma::text()
            ->using( 'deepl', ['api_key' => $_ENV['DEEPL_API_KEY']] )
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de', 'en' );

        $texts = $response->texts();

        $this->assertCount( 2, $texts );
        $this->assertStringContainsStringIgnoringCase( 'Hallo', $texts[0] );
    }


    public function testTranslateWithContext() : void
    {
        $response = Prisma::text()
            ->using( 'deepl', ['api_key' => $_ENV['DEEPL_API_KEY']] )
            ->ensure( 'translate' )
            ->translate( ['bank'], 'de', 'en', 'The bank of the river' );

        $texts = $response->texts();

        $this->assertCount( 1, $texts );
        $this->assertNotEmpty( $texts[0] );
    }
}
