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
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['GEMINI_API_KEY'] ) ) {
            $this->markTestSkipped( 'GEMINI_API_KEY is not defined in the environment' );
        }
    }


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


    public function testWrite() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::text()
            ->using( 'gemini', ['api_key' => $_ENV['GEMINI_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'What animal is in this image? Reply with just the animal name.', [$image] );

        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }
}
