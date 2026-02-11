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


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'audiopod', ['api_key' => $_ENV['AUDIOPOD_API_KEY']])
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        $this->assertNotEquals( 'audio/mpeg', $response->mimetype() );

        file_put_contents( __DIR__ . '/results/audiopod_speak.mp3', $response->binary() );
    }
}
