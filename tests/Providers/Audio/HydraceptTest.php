<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


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
        $this->assertSame( 'rcpt-1', $response->meta()['receiptId'] );
    }


    public function testSfx() : void
    {
        $this->hydraceptJob( 'audio', 'audio.sfx.generate.v1', 'audio/ogg', 'OGG' );

        $response = $this->provider()->sfx( 'Glass breaking', [
            'durationMs' => 1500,
            'outputFormatPreference' => 'opus_48000_128',
        ] );

        $this->assertStringContainsString( 'audio.sfx.generate.v1', (string) $this->requests()[1]->getUri() );
        $this->assertSame( 'OGG', $response->binary() );
    }


    public function testMusic() : void
    {
        $this->hydraceptJob( 'audio', 'audio.music.generate.v1', 'audio/mpeg', 'MUSIC' );

        $response = $this->provider()->music( 'Upbeat jazz', [
            'durationMs' => 10000,
            'forceInstrumental' => false,
            'outputFormatPreference' => 'mp3_44100_128',
        ] );

        $this->assertStringContainsString( 'audio.music.generate.v1', (string) $this->requests()[1]->getUri() );
        $this->assertSame( 'MUSIC', $response->binary() );
    }
}
