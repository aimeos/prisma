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
            ->stream( 'Reply with just the word "hello" in lowercase, nothing else.' );

        foreach( $response->stream() as $chunk ) {
            if( is_string( $chunk ) ) { $deltas[] = $chunk; }
        }

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    public function testStreamTools() : void
    {
        [$next, $ahead] = \Tests\Fixtures\PassphraseTools::make();

        $steps = [];
        $text = '';

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->withTools( [$next, $ahead] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQUIRED )
            ->withMaxSteps( 5 )
            ->ensure( 'stream' )
            ->stream( 'Give me the next passphrase and the passphrase for 2 days from now.' );

        foreach( $response->stream() as $chunk ) {
            if( is_string( $chunk ) ) {
                $text .= $chunk;
            } else {
                $steps[] = $chunk->name() . ':' . ( $chunk->done() ? 'done' : 'start' );
            }
        }

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
        [$next, $ahead] = \Tests\Fixtures\PassphraseTools::make();

        $response = Prisma::text()
            ->using( 'ollama', ['url' => $_ENV['OLLAMA_URL']] )
            ->withTools( [$next, $ahead, \Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQUIRED )
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
