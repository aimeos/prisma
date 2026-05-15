<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class OpenrouterTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['OPENROUTER_API_KEY'] ) ) {
            $this->markTestSkipped( 'OPENROUTER_API_KEY is not defined in the environment' );
        }
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }
}
