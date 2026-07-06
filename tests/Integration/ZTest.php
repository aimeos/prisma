<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class ZTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['Z_API_KEY'] ) ) {
            $this->markTestSkipped( 'Z_API_KEY is not defined in the environment' );
        }
    }

    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'z', ['api_key' => $_ENV['Z_API_KEY']] )
            ->ensure( 'stream' )
            ->stream( 'What is the capital of France? Reply with only the city name.' );

        foreach( $response->stream() as $chunk ) {
            if( is_string( $chunk ) ) {
                $deltas[] = $chunk;
            }
        }

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'z', ['api_key' => $_ENV['Z_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }
}
