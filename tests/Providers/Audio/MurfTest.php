<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MurfTest extends TestCase
{
    use MakesPrismaRequests;


    public function testRevoice() : void
    {
        $response = $this->prisma( 'audio', 'murf', ['api_key' => 'test'] )
            ->response( '{
                "audio_file": "https://murf.ai/link/to/audio/file",
                "audio_length_in_seconds": 8.75,
                "remaining_character_count": 992150
            }' )
            ->ensure( 'revoice' )
            ->revoice( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en-US-terrell' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.murf.ai/v1/voice-changer/convert', (string) $request->getUri() );
        } );

        $this->assertEquals( 'https://murf.ai/link/to/audio/file', $response->url() );
    }


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'murf', ['api_key' => 'test'] )
            ->response( 'MP3', ['Content-Type' => 'audio/mpeg'] )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'test', ['locale' => 'en-US', 'multiNativeLocale' => 'en-US'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://global.api.murf.ai/v1/speech/stream', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'falcon-2', $body['model'] );
            $this->assertEquals( 'en-US', $body['locale'] );
            $this->assertArrayNotHasKey( 'multiNativeLocale', $body );
            $this->assertArrayNotHasKey( 'modelVersion', $body );
        } );

        $this->assertEquals( 'MP3', $response->binary() );
        $this->assertEquals( 'audio/mpeg', $response->mimeType() );
    }
}
