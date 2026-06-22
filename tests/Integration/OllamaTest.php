<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class OllamaTest extends TestCase
{
    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->ensure( 'stream' )
            ->stream( 'Reply with just the word "hello" in lowercase, nothing else.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$deltas ) {
                if( is_string( $chunk ) ) { $deltas[] = $chunk; }
            } );

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    public function testStreamTools() : void
    {
        $next = \Aimeos\Prisma\Tools::make(
            'get_next_passphrase',
            'Returns the confidential passphrase for the next day. This is the only way to obtain it.',
            Schema::for( 'next_passphrase' ),
            fn() => 'wobbly-marmalade-1987'
        );

        $ahead = \Aimeos\Prisma\Tools::make(
            'get_passphrase_in_days',
            'Returns the confidential passphrase a given number of days ahead.',
            Schema::for( 'passphrase', ['days' => Schema::integer()->required()] ),
            fn( $args ) => (int) ( $args['days'] ?? 0 ) === 2 ? 'crimson-otter-4521' : 'unknown'
        );

        $steps = [];
        $text = '';

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->withTools( [$next, $ahead] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->withMaxSteps( 5 )
            ->ensure( 'stream' )
            ->stream( 'Give me the next passphrase and the passphrase for 2 days from now.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$steps, &$text ) {
                if( is_string( $chunk ) ) {
                    $text .= $chunk;
                } else {
                    $steps[] = $chunk->name() . ':' . ( $chunk->done() ? 'done' : 'start' );
                }
            } );

        $this->assertContains( 'get_next_passphrase:start', $steps );
        $this->assertContains( 'get_next_passphrase:done', $steps );
        $this->assertNotEmpty( $text );
        $this->assertGreaterThanOrEqual( 2, count( $response->steps() ) );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'crimson-otter-4521', $response->text() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testTools() : void
    {
        $next = \Aimeos\Prisma\Tools::make(
            'get_next_passphrase',
            'Returns the confidential passphrase for the next day. This is the only way to obtain it.',
            Schema::for( 'next_passphrase' ),
            fn() => 'wobbly-marmalade-1987'
        );

        $ahead = \Aimeos\Prisma\Tools::make(
            'get_passphrase_in_days',
            'Returns the confidential passphrase a given number of days ahead.',
            Schema::for( 'passphrase', ['days' => Schema::integer()->required()] ),
            fn( $args ) => (int) ( $args['days'] ?? 0 ) === 2 ? 'crimson-otter-4521' : 'unknown'
        );

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->withTools( [$next, $ahead, \Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Give me the next passphrase and the passphrase for 2 days from now.' );

        $this->assertGreaterThanOrEqual( 2, count( $response->steps() ) );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'crimson-otter-4521', $response->text() );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['OLLAMA_URL'] ) ) {
            $this->markTestSkipped( 'OLLAMA_URL is not defined in the environment' );
        }
    }
}
