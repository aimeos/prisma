<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;


class KimiTest extends TestCase
{
    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'kimi', ['api_key' => $_ENV['MOONSHOT_API_KEY']] )
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
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] )->strict();

        $response = Prisma::text()
            ->using( 'kimi', ['api_key' => $_ENV['MOONSHOT_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testTools() : void
    {
        $tool = Tools::make(
            'get_passphrase',
            'Returns the confidential passphrase. This is the only way to obtain it.',
            Schema::for( 'passphrase' ),
            fn() => 'wobbly-marmalade-1987'
        );

        $response = Prisma::text()
            ->using( 'kimi', ['api_key' => $_ENV['MOONSHOT_API_KEY']] )
            ->withTools( [$tool] )
            ->withToolChoice( Base::REQUIRED )
            ->withMaxSteps( 3 )
            ->ensure( 'write' )
            ->write( 'Give me the confidential passphrase.' );

        $this->assertNotEmpty( $response->steps() );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'kimi', ['api_key' => $_ENV['MOONSHOT_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['MOONSHOT_API_KEY'] ) ) {
            $this->markTestSkipped( 'MOONSHOT_API_KEY is not defined in the environment' );
        }
    }
}
