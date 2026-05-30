<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
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


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
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
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->withTools( [$next, $ahead, \Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Give me the next passphrase and the passphrase for 2 days from now.' );

        $this->assertGreaterThanOrEqual( 2, count( $response->steps() ) );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'crimson-otter-4521', $response->text() );
    }
}
