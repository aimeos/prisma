<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class PerplexityTest extends TestCase
{
    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'perplexity', ['api_key' => $_ENV['PERPLEXITY_API_KEY']] )
            ->ensure( 'stream' )
            ->stream( 'What is the capital of France? Reply with only the city name.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$deltas ) {
                if( is_string( $chunk ) ) {
                    $deltas[] = $chunk;
                }
            } );

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'perplexity', ['api_key' => $_ENV['PERPLEXITY_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'perplexity', ['api_key' => $_ENV['PERPLEXITY_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['PERPLEXITY_API_KEY'] ) ) {
            $this->markTestSkipped( 'PERPLEXITY_API_KEY is not defined in the environment' );
        }
    }
}
