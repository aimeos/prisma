<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class GeminiTest extends TestCase
{
    public function testDescribeAudio() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'greeting', $response->text() );
    }


    public function testDescribeImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $image );

        $this->assertStringContainsString( 'cartoon', $response->text() );
        $this->assertStringContainsString( 'cat', $response->text() );
    }


    public function testDescribeVideo() : void
    {
        $video = Video::fromLocalPath( __DIR__ . '/assets/pen.mp4' );
        $response = Prisma::video()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $video );

        $this->assertStringContainsStringIgnoringCase( 'pen', $response->text() );
    }


    public function testImagine() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']])
            ->withSystemPrompt( 'You are a cartoon artist.' )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog', [$image], ['aspectRatio' => '16:9'] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/gemini_imagine.png', $response->binary() );
    }


    public function testRepaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );

        $response = Prisma::image()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']])
            ->ensure( 'repaint' )
            ->repaint( $image, 'add eye glasses' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/gemini_repaint.png', $response->binary() );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
            ->ensure( 'stream' )
            ->stream( 'What is the capital of France? Reply with only the city name.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$deltas ) {
                if( is_string( $chunk ) ) {
                    $deltas[] = $chunk;
                }
            } );

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
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
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
            ->withTools( [$next, $ahead] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->withMaxSteps( 5 )
            ->ensure( 'stream' )
            ->stream( 'Give me the next passphrase and the passphrase for 2 days from now.', [], [], function( string|\Aimeos\Prisma\Tools\Step $chunk ) use ( &$steps, &$text ) {
                if( $chunk instanceof \Aimeos\Prisma\Tools\Step ) {
                    $steps[] = $chunk->name() . ':' . ( $chunk->done() ? 'done' : 'start' );
                } else {
                    $text .= $chunk;
                }
            } );

        // each executed tool is announced (start) and completed (done) over the stream
        $this->assertContains( 'get_next_passphrase:start', $steps );
        $this->assertContains( 'get_next_passphrase:done', $steps );

        // the final answer is streamed after the tool loop folds the results back in
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
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
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
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
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
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::text()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'What animal is in this image? Reply with just the animal name.', [$image] );

        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['GEMINI_API_KEY'] ) ) {
            $this->markTestSkipped( 'GEMINI_API_KEY is not defined in the environment' );
        }
    }
}
