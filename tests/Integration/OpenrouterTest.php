<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class OpenrouterTest extends TestCase
{
    public function testDescribeAudio() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'af_alloy' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
    }


    public function testDescribeImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $image );

        $this->assertStringContainsStringIgnoringCase( 'cartoon', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }


    public function testImagineImage() : void
    {
        $response = Prisma::image()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A simple red circle on a white background.' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
    }


    public function testRecognizeImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/text.png' );
        $response = Prisma::image()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'recognize' )
            ->recognize( $image );

        $this->assertStringContainsStringIgnoringCase( 'This is text', $response->text() );
    }


    public function testRepaintImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $image, 'Add eye glasses to the cat.' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
    }


    public function testDescribeVideo() : void
    {
        $video = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $video );

        $this->assertStringContainsStringIgnoringCase( 'flower', $response->text() );
    }


    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street.' );

        $video = $response->first();

        $this->assertInstanceOf( Video::class, $video );
        $this->assertNotEmpty( $video->url() ?? $video->binary() );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
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
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testTools() : void
    {
        [$next, $ahead] = \Tests\Fixtures\PassphraseTools::make();

        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->withTools( [$next, $ahead, \Aimeos\Prisma\Tools::provider( 'web_search' )] )
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
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'transcribe' )
            ->transcribe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    public function testVectorizeImage() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'vectorize' )
            ->vectorize( [$image] );

        $this->assertCount( 1, $response->vectors() );
        $this->assertNotEmpty( $response->first() );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'openrouter', ['api_key' => $_ENV['OPENROUTER_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['OPENROUTER_API_KEY'] ) ) {
            $this->markTestSkipped( 'OPENROUTER_API_KEY is not defined in the environment' );
        }
    }
}
