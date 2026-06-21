<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class OpenaiTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['OPENAI_API_KEY'] ) ) {
            $this->markTestSkipped( 'OPENAI_API_KEY is not defined in the environment' );
        }
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']] )
            ->ensure( 'stream' )
            ->stream( 'What is the capital of France? Reply with only the city name.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$deltas ) {
                if( is_string( $chunk ) ) {
                    $deltas[] = $chunk;
                }
            } );

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    public function testDescribeAudio() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'greeting', $response->text() );
    }


    public function testDescribeImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $image );

        $this->assertStringContainsStringIgnoringCase( 'cartoon', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }


    public function testImagine() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog', [$image] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/openai_imagine.png', $response->binary() );
    }


    public function testInpaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );

        $response = Prisma::image()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'inpaint' )
            ->inpaint( $image, $mask, 'add eye glasses' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/openai_inpaint.png', $response->binary() );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'alloy' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/openai_speak.mp3', $response->binary() );
    }


    public function testWrite() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::text()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'What animal is in this image? Reply with just the animal name.', [$image] );

        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testTranscribe() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']])
            ->ensure( 'transcribe' )
            ->transcribe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'Hello', $response->text() );
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
            ->using( 'openai', ['api_key' => $_ENV['OPENAI_API_KEY']] )
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
