<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;


class AudiopodTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['AUDIOPOD_API_KEY'] ) ) {
            $this->markTestSkipped( 'AUDIOPOD_API_KEY is not defined in the environment' );
        }
    }


    public function testDemix() : void
    {
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'demix' )
            ->demix( Audio::fromLocalPath( __DIR__ . '/assets/musicfox.mp3' ), 3 );

        foreach( $response as $name => $file ) {
            file_put_contents( __DIR__ . '/results/audiopod_demix_' . $name . '.mp3', $file->binary() );
        }

        $this->assertEquals( 'audio/mpeg', $response->mimetype() );
    }


    public function testDenoise() : void
    {
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'denoise' )
            ->denoise( Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' ), ['quality_mode' => 'aggressive'] );

        file_put_contents( __DIR__ . '/results/audiopod_denoise.wav', $response->binary() );

        $this->assertEquals( 'audio/x-wav', $response->mimetype() );
    }


    public function testRevoice() : void
    {
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'revoice' )
            ->revoice( Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' ), 'b76f1226-8170-4902-9482-36bb4fc98085' );

        file_put_contents( __DIR__ . '/results/audiopod_revoice.mp3', $response->binary() );

        $this->assertEquals( 'audio/x-wav', $response->mimetype() );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        file_put_contents( __DIR__ . '/results/audiopod_speak.mp3', $response->binary() );

        $this->assertEquals( 'audio/mpeg', $response->mimetype() );
    }


    public function testTranscribe() : void
    {
        $audio = Audio::fromLocalPath( __DIR__ . '/assets/hello.mp3' );
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'transcribe' )
            ->transcribe( $audio );

        $this->assertStringContainsStringIgnoringCase( 'Hello', $response->text() );
    }
}
