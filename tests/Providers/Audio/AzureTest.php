<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AzureTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $prisma = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'url' => 'https://myres.openai.azure.com'] );
        $prisma->response( json_encode( ['text' => 'Hello.'] ) );
        $response = $prisma->response( json_encode( [
            'choices' => [['message' => ['content' => 'a greeting']]],
            'usage' => ['total_tokens' => 7],
        ] ) )
            ->ensure( 'describe' )
            ->describe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            if( (string) $request->getUri() !== 'https://myres.openai.azure.com/openai/v1/chat/completions' ) {
                return false;
            }

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-5.6-luna', $body['model'] );
        } );

        $this->assertEquals( 'a greeting', $response->text() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'audio', 'azure', ['url' => 'https://myres.openai.azure.com'] );
    }


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'url' => 'https://myres.openai.azure.com'] )
            ->response( 'MP3', headers: ['Content-Type' => 'audio/mpeg'] )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'nova', ['speed' => 1.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://myres.openai.azure.com/openai/v1/audio/speech', (string) $request->getUri() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'api-key' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-4o-mini-tts', $body['model'] );
            $this->assertEquals( 'This is a test.', $body['input'] );
            $this->assertEquals( 'nova', $body['voice'] );
            $this->assertEquals( 1.2, $body['speed'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( 'MP3', $response->binary() );
        $this->assertEquals( 'audio/mpeg', $response->mimetype() );
    }


    public function testTranscribe() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'url' => 'https://myres.openai.azure.com'] )
            ->response( '{"text":"Hello.","usage":{"type":"tokens","total_tokens":12}}' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://myres.openai.azure.com/openai/v1/audio/transcriptions', (string) $request->getUri() );

            $body = $request->getBody()->getContents();
            $this->assertStringContainsString( "name=\"model\"\r\nContent-Length: 17\r\n\r\ngpt-4o-transcribe", $body );
            $this->assertStringContainsString( "name=\"response_format\"\r\nContent-Length: 4\r\n\r\njson", $body );
            $this->assertStringContainsString( "name=\"language\"\r\nContent-Length: 2\r\n\r\nen", $body );
        } );

        $this->assertEquals( 'Hello.', $response->text() );
    }


    public function testTranscribeWhisper() : void
    {
        $response = $this->prisma( 'audio', 'azure', ['api_key' => 'test', 'url' => 'https://myres.openai.azure.com'] )
            ->response( '{"text":"Hello.","segments":[{"id":0,"start":0.0,"end":1.0,"text":" Hello."}]}' )
            ->model( 'whisper' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = $request->getBody()->getContents();
            $this->assertStringContainsString( "\r\n\r\nverbose_json", $body );
            $this->assertStringNotContainsString( 'name="language"', $body );
        } );

        $this->assertEquals( 'Hello.', $response->text() );
        $this->assertCount( 1, $response->structured() );
    }
}
