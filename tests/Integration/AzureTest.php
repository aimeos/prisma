<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class AzureTest extends TestCase
{
    public function testSpeak() : void
    {
        $response = $this->audio()
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        $this->assertNotEmpty( $response->binary() );
    }


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
        [$next, $ahead] = \Tests\Fixtures\PassphraseTools::make();

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


    public function testTranscribe() : void
    {
        $response = $this->audio()
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' ) );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    public function testTranslate() : void
    {
        $response = $this->provider()
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de', 'en' );

        $this->assertEquals( ['Hallo', 'Welt'], $response->texts() );
    }


    public function testWrite() : void
    {
        $response = $this->provider()
            ->ensure( 'write' )
            ->write( 'What is the capital of France? Reply with only the city name.' );

        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    /**
     * Builds an Azure audio provider configured from the environment.
     *
     * @return \Aimeos\Prisma\Contracts\Provider Configured Azure provider
     */
    protected function audio() : \Aimeos\Prisma\Contracts\Provider
    {
        if( empty( $_ENV['AZURE_REGION'] ) ) {
            $this->markTestSkipped( 'AZURE_REGION is not defined in the environment' );
        }

        return Prisma::audio()->using( 'azure', ['api_key' => $_ENV['AZURE_API_KEY'], 'region' => $_ENV['AZURE_REGION']] );
    }


    /**
     * Builds an Azure text provider configured from the environment.
     *
     * @return \Aimeos\Prisma\Contracts\Provider Configured Azure provider
     */
    protected function provider() : \Aimeos\Prisma\Contracts\Provider
    {
        $config = ['api_key' => $_ENV['AZURE_API_KEY'], 'url' => $_ENV['AZURE_URL']];

        return Prisma::text()->using( 'azure', $config )->model( $_ENV['AZURE_DEPLOYMENT'] ?? 'gpt-5.6-sol' );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['AZURE_API_KEY'] ) || empty( $_ENV['AZURE_URL'] ) ) {
            $this->markTestSkipped( 'AZURE_API_KEY / AZURE_URL are not defined in the environment' );
        }
    }
}
