<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class XaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Hello world'
                    ]
                ]],
                'usage' => ['total_tokens' => 10, 'prompt_tokens' => 5, 'completion_tokens' => 5]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.x.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'grok-3', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'An image of a cat'
                    ]
                ]]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'image_url', $content[0]['type'] );
            $this->assertEquals( 'text', $content[1]['type'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Bonjour'
                    ]
                ]]
            ] );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['messages'] );
            $this->assertEquals( 'system', $body['messages'][0]['role'] );
            $this->assertEquals( 'Always respond in French', $body['messages'][0]['content'] );
        } );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'result'
                    ]
                ]]
            ] )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertEquals( 100, $body['max_tokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'The answer is 42.',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/answer',
                            'title' => 'The Answer',
                            'start_index' => 0,
                            'end_index' => 17
                        ]]
                    ]]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->write( 'What is the answer?' );

        $citations = $response->citations();
        $this->assertCount( 1, $citations );
        $this->assertEquals( 'The Answer', $citations[0]->title() );
        $this->assertEquals( 'https://example.com/answer', $citations[0]->url() );
        $this->assertEquals( 'The answer is 42.', $citations[0]->text() );
        $this->assertNull( $citations[0]->source() );
    }


    public function testWriteWithCitationsCompletions() : void
    {
        $response = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Hello world'
                    ]
                ]],
                'citations' => [
                    'https://example.com/source'
                ],
                'usage' => ['total_tokens' => 5]
            ] )
            ->write( 'Say hello' );

        $citations = $response->citations();
        $this->assertCount( 1, $citations );
        $this->assertEquals( 'https://example.com/source', $citations[0]->url() );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteWithProviderTools() : void
    {
        $result = $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Search result'
                    ]]
                ]],
                'usage' => ['total_tokens' => 10, 'input_tokens' => 5, 'output_tokens' => 5]
            ] )
            ->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->write( 'Search for something' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.x.ai/v1/responses', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertArrayHasKey( 'input', $body );
            $this->assertArrayNotHasKey( 'messages', $body );
            $this->assertArrayHasKey( 'tools', $body );

            $hasWebSearch = false;
            foreach( $body['tools'] as $tool ) {
                if( ( $tool['type'] ?? '' ) === 'web_search' ) {
                    $hasWebSearch = true;
                }
            }
            $this->assertTrue( $hasWebSearch );
        } );

        $this->assertEquals( 'Search result', $result->text() );
    }


    public function testWriteWithProviderToolsSystemPrompt() : void
    {
        $this->prisma( 'text', 'xai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'result'
                    ]]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->withSystemPrompt( 'Be helpful' )
            ->write( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Be helpful', $body['instructions'] );
        } );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'xai', [] );
    }
}
