<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;


class GroqTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['GROQ_API_KEY'] ) ) {
            $this->markTestSkipped( 'GROQ_API_KEY is not defined in the environment' );
        }
    }


    public function testDescribe() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'groq', ['api_key' => $_ENV['GROQ_API_KEY']])
            ->ensure( 'describe' )
            ->describe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'greeting', $response->text() );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'groq', ['api_key' => $_ENV['GROQ_API_KEY']])
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/groq_speak.mp3', $response->binary() );
    }


    public function testTranscribe() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'groq', ['api_key' => $_ENV['GROQ_API_KEY']])
            ->ensure( 'transcribe' )
            ->transcribe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'Hello', $response->text() );
    }
}
