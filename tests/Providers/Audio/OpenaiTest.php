<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $prisma = $this->prisma( 'audio', 'openai', ['api_key' => 'test'] );
        $prisma->response( json_encode( [
            'text' => 'an audio description'
        ] ) );
        $response = $prisma->response( json_encode( [
            'choices' => [[
                'message' => [
                    'content' => 'an audio description'
                ]
            ]]
        ] ) )
            ->ensure( 'describe' )
            ->describe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            if( (string) $request->getUri() !== 'https://api.openai.com/v1/chat/completions' ) {
                return false;
            }

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-5.6-luna', $body['model'] );
        } );

        $this->assertEquals( 'an audio description', $response->text() );
    }


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'openai', ['api_key' => 'test'] )
            ->response( 'MP3' )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'test' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.openai.com/v1/audio/speech', (string) $request->getUri() );
        } );

        $this->assertEquals( 'MP3', $response->binary() );
    }


    public function testTranscribe() : void
    {
        $response = $this->prisma( 'audio', 'openai', ['api_key' => 'test'] )
            ->response( '{
                "task":"transcribe",
                "language":"english",
                "duration":1.1699999570846558,
                "text":"Hello.",
                "segments":[{
                    "id":0,
                    "seek":0,
                    "start":0.0,
                    "end":1.0,
                    "text":" Hello.",
                    "tokens":[50364,2425,13,50414],
                    "temperature":0.0,
                    "avg_logprob":-0.8247560262680054,
                    "compression_ratio":0.4285714328289032,
                    "no_speech_prob":0.25014108419418335
                }],
                "usage":{
                    "type":"duration",
                    "seconds":2
                }
            }' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.openai.com/v1/audio/transcriptions', (string) $request->getUri() );
        } );

        $this->assertEquals( 'Hello.', $response->text() );
    }


    #[DataProvider( 'browserContainerProvider' )]
    public function testTranscribeBrowserContainerFilename( string $mimeType, string $filename ) : void
    {
        $this->prisma( 'audio', 'openai', ['api_key' => 'test'] )
            ->response( '{"text":"Hello."}' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'audio data', $mimeType ) );

        $this->assertPrismaRequest( function( $request, $options ) use ( $mimeType, $filename ) {
            $body = $request->getBody()->getContents();

            $this->assertStringContainsString( 'filename="' . $filename . '"', $body );
            $this->assertStringContainsString( 'Content-Type: ' . $mimeType, $body );
        } );
    }


    /**
     * @return array<string, array{string, string}>
     */
    public static function browserContainerProvider() : array
    {
        return [
            'mp4' => ['video/mp4', 'audio.mp4'],
            'ogg' => ['video/ogg', 'audio.ogg'],
            'webm' => ['video/webm', 'audio.webm'],
        ];
    }
}
