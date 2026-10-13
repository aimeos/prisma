<?php

namespace Tests\Integration;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;


class MetaTest extends TestCase
{
    public function testDescribeAudio() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.wav' );
        $response = Prisma::audio()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'greeting', $response->text() );
    }


    public function testDescribeVideo() : void
    {
        $video = Video::fromLocalPath( __DIR__ . '/assets/flower.mp4', 'video/mp4' );
        $response = Prisma::video()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'describe' )
            ->describe( $video );

        $this->assertStringContainsStringIgnoringCase( 'flower', $response->text() );
    }


    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'A red fox trotting through fresh snow', [], ['reasoning_strength' => 'low'] );

        $this->assertNotEmpty( $response->binary() );
    }


    public function testRepaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );

        $response = Prisma::image()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'repaint' )
            ->repaint( $image, 'Add a small red wool hat', ['reasoning_strength' => 'low'] );

        $this->assertNotEmpty( $response->binary() );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = Prisma::text()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
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
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
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
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->withTools( [$tool] )
            ->withToolChoice( Base::REQUIRED )
            ->withMaxSteps( 3 )
            ->ensure( 'write' )
            ->write( 'Give me the confidential passphrase.' );

        $this->assertNotEmpty( $response->steps() );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
    }


    public function testTranscribe() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.wav' );
        $response = Prisma::audio()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'transcribe' )
            ->transcribe( $audio, 'en' );

        $this->assertStringContainsStringIgnoringCase( 'Hello', $response->text() );
    }


    public function testWrite() : void
    {
        $response = Prisma::text()
            ->using( 'meta', ['api_key' => $_ENV['META_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'Reply with just the word "hello" in lowercase, nothing else.' );

        $this->assertStringContainsStringIgnoringCase( 'hello', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['META_API_KEY'] ) ) {
            $this->markTestSkipped( 'META_API_KEY is not defined in the environment' );
        }
    }
}
