<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenrouterTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'audio', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'A short conversation']]],
                'usage' => ['total_tokens' => 12],
            ] )
            ->ensure( 'describe' )
            ->describe( Audio::fromBinary( 'WAV', 'audio/wav' ), 'de', [
                'temperature' => 0.2,
                'duration' => 10,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'https://openrouter.ai/api/v1/chat/completions', (string) $request->getUri() );
            $this->assertSame( 'google/gemini-3.7-flash', $body['model'] );
            $this->assertSame( 'input_audio', $content[0]['type'] );
            $this->assertSame( base64_encode( 'WAV' ), $content[0]['input_audio']['data'] );
            $this->assertSame( 'wav', $content[0]['input_audio']['format'] );
            $this->assertStringContainsString( '"de"', $content[1]['text'] );
            $this->assertSame( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'duration', $body );
        } );

        $this->assertSame( 'A short conversation', $response->text() );
    }


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'openrouter', ['api_key' => 'test'] )
            ->response( 'MP3', [
                'Content-Type' => 'audio/mpeg',
                'X-Generation-Id' => 'gen-1',
            ] )
            ->ensure( 'speak' )
            ->speak( 'Hello there', 'af_nova', [
                'response_format' => 'mp3',
                'speed' => 1.2,
                'unknown' => true,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );

            $this->assertSame( 'https://openrouter.ai/api/v1/audio/speech', (string) $request->getUri() );
            $this->assertSame( 'hexgrad/kokoro-82m', $body['model'] );
            $this->assertSame( 'Hello there', $body['input'] );
            $this->assertSame( 'af_nova', $body['voice'] );
            $this->assertSame( 'mp3', $body['response_format'] );
            $this->assertSame( 1.2, $body['speed'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertSame( 'MP3', $response->binary() );
        $this->assertSame( 'audio/mpeg', $response->mimeType() );
        $this->assertSame( 'gen-1', $response->meta()['generation_id'] );
    }


    public function testTranscribe() : void
    {
        $audio = Audio::fromBinary( 'WEBM', 'video/webm' )->as( 'recording.webm' );
        $response = $this->prisma( 'audio', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'text' => 'Hello.',
                'language' => 'en',
                'segments' => [['id' => 0, 'text' => 'Hello.']],
                'usage' => ['seconds' => 2.5, 'total_tokens' => 14],
            ] )
            ->ensure( 'transcribe' )
            ->transcribe( $audio, 'en', [
                'response_format' => 'verbose_json',
                'timestamp_granularities' => ['segment'],
                'temperature' => 0,
                'prompt' => 'ignored',
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );

            $this->assertSame( 'https://openrouter.ai/api/v1/audio/transcriptions', (string) $request->getUri() );
            $this->assertSame( 'application/json', $request->getHeaderLine( 'Content-Type' ) );
            $this->assertSame( 'openai/whisper-large-v3', $body['model'] );
            $this->assertSame( base64_encode( 'WEBM' ), $body['input_audio']['data'] );
            $this->assertSame( 'webm', $body['input_audio']['format'] );
            $this->assertSame( 'en', $body['language'] );
            $this->assertSame( 'verbose_json', $body['response_format'] );
            $this->assertArrayNotHasKey( 'prompt', $body );
        } );

        $this->assertSame( 'Hello.', $response->text() );
        $this->assertSame( [['id' => 0, 'text' => 'Hello.']], $response->structured() );
        $this->assertSame( 14, $response->usage()->totalTokens() );
        $this->assertSame( 'en', $response->meta()['language'] );
        $this->assertFalse( $this->provider()->has( 'vectorize' ) );
    }
}
