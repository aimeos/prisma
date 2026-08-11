<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class RequestyTest extends TestCase
{
    public function testStream() : void
    {
        $response = $this->provider()->stream( 'What is the capital of France? Reply with only the city name.' );
        $deltas = iterator_to_array( $response->stream() );

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    public function testStructure() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] )->strict();

        $response = $this->provider()->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testVectorize() : void
    {
        $response = $this->provider()
            ->model( 'openai/text-embedding-3-small' )
            ->vectorize( ['hello'] );

        $this->assertNotEmpty( $response->first() );
    }


    public function testWrite() : void
    {
        $response = $this->provider()->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    private function provider() : \Aimeos\Prisma\Contracts\Provider
    {
        return Prisma::text()->using( 'requesty', ['api_key' => $_ENV['REQUESTY_API_KEY']] );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['REQUESTY_API_KEY'] ) ) {
            $this->markTestSkipped( 'REQUESTY_API_KEY is not defined in the environment' );
        }
    }
}
