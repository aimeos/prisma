<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


    public function testCompose() : void
    {
        $this->hydraceptJob( 'audio', 'audio.music.generate.v1', 'audio/mpeg', 'MUSIC' );

        $response = $this->provider()->ensure( 'compose' )->compose( 'Upbeat jazz', [
            'duration' => 10,
            'forceInstrumental' => false,
            'outputFormatPreference' => 'mp3_44100_128',
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertStringContainsString( 'audio.music.generate.v1', (string) $this->requests()[0]->getUri() );
        $this->assertSame( 10000, $body['input']['durationMs'] );
        $this->assertFalse( $body['input']['forceInstrumental'] );
        $this->assertSame( 'MUSIC', $response->binary() );
    }


    public function testSpeakRequiresVoice() : void
    {
        $this->prisma( 'audio', 'hydracept', ['api_key' => 'test-key'] );
        $this->expectException( BadRequestException::class );
        $this->provider()->speak( 'Hello' );
    }


    public function testSpeak() : void
    {
        $this->hydraceptJob( 'audio', 'audio.voice.generate.v1', 'audio/mpeg', 'MP3' );

        $response = $this->provider()->ensure( 'speak' )->speak( 'Hello world', 'voice-1', [
            'outputFormatPreference' => 'mp3_44100_128',
            'voiceSettings' => ['stability' => 0.5],
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'voice-1', $body['input']['voiceId'] );
        $this->assertSame( 'mp3_44100_128', $body['input']['outputFormatPreference'] );
        $this->assertSame( 'MP3', $response->binary() );
        $this->assertSame( 'rcpt-1', $response->usage()['receiptId'] );
    }


    public function testSynthesize() : void
    {
        $this->hydraceptJob( 'audio', 'audio.sfx.generate.v1', 'audio/ogg', 'OGG' );

        $response = $this->provider()->ensure( 'synthesize' )->synthesize( 'Glass breaking', [
            'duration' => 2,
            'durationMs' => 1500,
            'outputFormatPreference' => 'opus_48000_128',
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertStringContainsString( 'audio.sfx.generate.v1', (string) $this->requests()[0]->getUri() );
        $this->assertSame( 1500, $body['input']['durationMs'] );
        $this->assertSame( 'OGG', $response->binary() );
    }


    public function testSpeakModel() : void
    {
        $this->hydraceptJob( 'audio', 'audio.voice.generate.v1', 'audio/mpeg', 'MP3' );

        $this->provider()->model( 'eleven_flash_v2_5' )->speak( 'Hello world', 'voice-1', ['modelId' => 'other'] )->ready();

        // the model set by model() wins over the native option
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'eleven_flash_v2_5', $body['input']['modelId'] );
    }
}
