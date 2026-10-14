<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AzureTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'audio', 'azure', ['region' => 'westeurope'] );
    }


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'region' => 'westeurope'] )
            ->response( 'MP3', headers: ['Content-Type' => 'audio/mpeg'] )
            ->ensure( 'speak' )
            ->speak( 'Fish & <chips>', 'de-DE-KatjaNeural', ['format' => 'audio-16khz-32kbitrate-mono-mp3'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://westeurope.tts.speech.microsoft.com/cognitiveservices/v1', (string) $request->getUri() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'Ocp-Apim-Subscription-Key' ) );
            $this->assertEquals( 'application/ssml+xml', $request->getHeaderLine( 'Content-Type' ) );
            $this->assertEquals( 'audio-16khz-32kbitrate-mono-mp3', $request->getHeaderLine( 'X-Microsoft-OutputFormat' ) );
            $this->assertEquals(
                '<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="de-DE">'
                . '<voice name="de-DE-KatjaNeural">Fish &amp; &lt;chips&gt;</voice></speak>',
                $request->getBody()->getContents()
            );
        } );

        $this->assertEquals( 'MP3', $response->binary() );
        $this->assertEquals( 'audio/mpeg', $response->mimetype() );
    }


    public function testSpeakDefaults() : void
    {
        $this->prisma( 'audio', 'azure', ['api_key' => 'test'] )
            ->response( 'MP3' )
            ->ensure( 'speak' )
            ->speak( 'Hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://eastus.tts.speech.microsoft.com/cognitiveservices/v1', (string) $request->getUri() );
            $this->assertEquals( 'audio-24khz-48kbitrate-mono-mp3', $request->getHeaderLine( 'X-Microsoft-OutputFormat' ) );
            $this->assertStringContainsString( 'xml:lang="en-US"><voice name="en-US-AvaMultilingualNeural">', $request->getBody()->getContents() );
        } );
    }


    public function testTranscribe() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'region' => 'westeurope'] )
            ->response( json_encode( [
                'durationMilliseconds' => 1200,
                'combinedPhrases' => [['text' => 'Hello.']],
                'phrases' => [['offsetMilliseconds' => 40, 'durationMilliseconds' => 800, 'text' => 'Hello.', 'locale' => 'en-US', 'confidence' => 0.97]],
            ] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en-US', ['profanityFilterMode' => 'None', 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://westeurope.api.cognitive.microsoft.com/speechtotext/transcriptions:transcribe?api-version=2024-11-15',
                (string) $request->getUri()
            );
            $this->assertEquals( 'test', $request->getHeaderLine( 'Ocp-Apim-Subscription-Key' ) );

            $body = $request->getBody()->getContents();
            $this->assertStringContainsString( '{"profanityFilterMode":"None","locales":["en-US"]}', $body );
            $this->assertStringContainsString( 'name="audio"; filename="audio.mp3"', $body );
            $this->assertStringNotContainsString( 'unknown', $body );
        } );

        $this->assertEquals( 'Hello.', $response->text() );
        $this->assertEquals( 0.97, $response->structured()[0]['confidence'] );
        $this->assertEquals( 1200, $response->meta()['durationMilliseconds'] );
    }


    public function testTranscribeChannels() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test'] )
            ->response( json_encode( [
                'combinedPhrases' => [['channel' => 0, 'text' => 'Hello.'], ['channel' => 1, 'text' => 'Hi.']],
                'phrases' => [],
            ] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'WAV', 'audio/wav' ), null, ['channels' => [0, 1]] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = $request->getBody()->getContents();
            $this->assertStringContainsString( 'https://eastus.api.cognitive.microsoft.com/', (string) $request->getUri() );
            $this->assertStringContainsString( '{"channels":[0,1]}', $body );
        } );

        $this->assertEquals( "Hello.\nHi.", $response->text() );
    }


    public function testTranscribeError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'audio', 'azure', ['api_key' => 'test'] )
            ->response( ['error' => ['code' => 'InvalidRequest', 'message' => 'Unsupported audio']], status: 400, reason: 'Bad Request' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'WAV', 'audio/wav' ) );
    }


    public function testTranscribeLanguageDetection() : void
    {
        $this->prisma( 'audio', 'azure', ['api_key' => 'test'] )
            ->response( json_encode( ['combinedPhrases' => [['text' => 'Hallo.']], 'phrases' => []] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'WAV', 'audio/wav' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertStringContainsString( "name=\"definition\"\r\nContent-Length: 2\r\n\r\n{}", $request->getBody()->getContents() );
        } );
    }
}
