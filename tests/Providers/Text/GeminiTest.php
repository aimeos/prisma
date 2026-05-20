<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GeminiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Hello world'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-goog-api-key' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Say hello', $body['contents'][0]['parts'][0]['text'] );
            $this->assertEquals( ['TEXT'], $body['generationConfig']['responseModalities'] );
            $this->assertArrayNotHasKey( 'systemInstruction', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'An image of a cat'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['contents'][0]['parts'] );
            $this->assertArrayHasKey( 'inlineData', $body['contents'][0]['parts'][0] );
            $this->assertEquals( 'Describe this image', $body['contents'][0]['parts'][1]['text'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Bonjour'
                        ]]
                    ]
                ]]
            ] ) );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Always respond in French', $body['systemInstruction']['parts'][0]['text'] );
        } );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'result'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['generationConfig']['temperature'] );
            $this->assertEquals( 100, $body['generationConfig']['maxOutputTokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body['generationConfig'] );
        } );
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Paris is the capital of France.'
                        ]]
                    ],
                    'groundingMetadata' => [
                        'groundingChunks' => [[
                            'web' => [
                                'uri' => 'https://example.com/france',
                                'title' => 'France Facts'
                            ]
                        ], [
                            'web' => [
                                'uri' => 'https://example.com/paris',
                                'title' => 'Paris Guide'
                            ]
                        ]],
                        'groundingSupports' => [[
                            'segment' => [
                                'startIndex' => 0,
                                'endIndex' => 30
                            ],
                            'groundingChunkIndices' => [0, 1]
                        ]]
                    ]
                ]]
            ] ) )
            ->write( 'What is the capital of France?' );

        $citations = $response->citations();
        $this->assertCount( 2, $citations );
        $this->assertEquals( 'France Facts', $citations[0]['title'] );
        $this->assertEquals( 'https://example.com/france', $citations[0]['url'] );
        $this->assertEquals( 'Paris is the capital of France', $citations[0]['text'] );
        $this->assertNull( $citations[0]['source'] );
        $this->assertEquals( 'Paris Guide', $citations[1]['title'] );
        $this->assertEquals( 'https://example.com/paris', $citations[1]['url'] );
        $this->assertEquals( 'Paris is the capital of France', $citations[1]['text'] );
    }


    public function testWriteWithoutCitations() : void
    {
        $response = $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'Hello'
                        ]]
                    ]
                ]]
            ] ) )
            ->write( 'Say hello' );

        $this->assertEmpty( $response->citations() );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( ['error' => ['message' => 'Bad request']] ), status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }




    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'gemini', [] );
    }
}
