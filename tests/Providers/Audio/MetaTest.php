<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MetaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $prisma = $this->prisma( 'audio', 'meta', ['api_key' => 'test'] );
        $prisma->response( json_encode( [
            'sessionId' => 'abc',
            'transcript' => 'Hello world',
            'audioDurationMs' => 1200,
            'turns' => []
        ] ) );
        $response = $prisma->response( json_encode( [
            'choices' => [[
                'message' => [
                    'content' => 'a greeting'
                ]
            ]]
        ] ) )
            ->ensure( 'describe' )
            ->describe( Audio::fromBinary( 'WAV', 'audio/wav' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            if( (string) $request->getUri() !== 'https://api.meta.ai/v1/chat/completions' ) {
                return false;
            }

            $this->assertEquals( 'muse-spark-1.3', json_decode( (string) $request->getBody(), true )['model'] );
        } );

        $this->assertEquals( 'a greeting', $response->text() );
    }


    public function testTranscribe() : void
    {
        $response = $this->prisma( 'audio', 'meta', ['api_key' => 'test'] )
            ->response( json_encode( [
                'sessionId' => 'abc',
                'transcript' => 'How is the weather? It is raining.',
                'audioDurationMs' => 8240,
                'turns' => [
                    ['turnId' => 1, 'startMs' => 1520, 'endMs' => 4640, 'transcript' => 'How is the weather?', 'speaker' => 'A'],
                    ['turnId' => 2, 'startMs' => 5900, 'endMs' => 8240, 'transcript' => 'It is raining.', 'speaker' => 'B'],
                ]
            ] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'WAV', 'audio/wav' ), 'fr', ['mode' => 'DIARIZATION', 'keywords' => ['Aimeos'], 'unknown' => 1] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.meta.ai/v1/asr/transcribe', (string) $request->getUri() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'application/json', $request->getHeaderLine( 'Accept' ) );
            $this->assertStringContainsString( 'name="audio"', $body );
            $this->assertStringContainsString( 'name="request"', $body );
            $this->assertStringContainsString( '{"model":"muse-voice-transcribe-1.0","audioEncoding":"WAV","mode":"DIARIZATION","keywords":["Aimeos"],"languageBias":["French"]}', $body );
            $this->assertStringNotContainsString( 'unknown', $body );
        } );

        $this->assertEquals( 'How is the weather? It is raining.', $response->text() );
        $this->assertEquals( 'A', $response->structured()[0]['speaker'] );
        $this->assertEquals( 8.0, $response->usage()['used'] );
        $this->assertEquals( 'abc', $response->meta()['sessionId'] );
    }


    public function testTranscribeLanguageName() : void
    {
        $this->prisma( 'audio', 'meta', ['api_key' => 'test'] )
            ->response( json_encode( ['sessionId' => 'abc', 'transcript' => 'Hallo', 'audioDurationMs' => 900, 'turns' => []] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'WAV', 'audio/wav' ), 'Mandarin Chinese' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertStringContainsString( '"languageBias":["Mandarin Chinese"]', (string) $request->getBody() );
        } );
    }
}
