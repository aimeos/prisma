<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class AzureTest extends TestCase
{
    public function testStream() : void
    {
        $deltas = [];

        $response = $this->provider()
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


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );

        $response = $this->provider()
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

        $response = $this->provider()
            ->withTools( [$next, $ahead] )
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
        $response = $this->provider()
            ->ensure( 'write' )
            ->write( 'What is the capital of France? Reply with only the city name.' );

        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    /**
     * Builds an Azure text provider configured from the environment.
     *
     * @return \Aimeos\Prisma\Contracts\Provider Configured Azure provider
     */
    protected function provider() : \Aimeos\Prisma\Contracts\Provider
    {
        $config = ['api_key' => $_ENV['AZURE_API_KEY'], 'resource' => $_ENV['AZURE_RESOURCE']];

        if( !empty( $_ENV['AZURE_API_VERSION'] ) ) {
            $config['api_version'] = $_ENV['AZURE_API_VERSION'];
        }

        return Prisma::text()->using( 'azure', $config )->model( $_ENV['AZURE_DEPLOYMENT'] ?? 'gpt-4o' );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['AZURE_API_KEY'] ) || empty( $_ENV['AZURE_RESOURCE'] ) ) {
            $this->markTestSkipped( 'AZURE_API_KEY / AZURE_RESOURCE are not defined in the environment' );
        }
    }
}
