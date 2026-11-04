<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class PerplexityTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'perplexity', [] );
    }


    public function testStream() : void
    {
        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['type' => 'response.created', 'response' => ['status' => 'in_progress']]],
                ['data' => ['type' => 'response.output_text.delta', 'delta' => 'Hello']],
                ['data' => ['type' => 'response.output_text.delta', 'delta' => ' world']],
                ['data' => ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello world']]]], 'usage' => ['total_tokens' => 9]]]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        $deltas = [];

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.perplexity.ai/v1/agent', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['stream'] );
            $this->assertEquals( 'fast', $body['effort'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 9, $response->usage()['used'] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"name":"John","age":30}']]]],
                'usage' => ['total_tokens' => 15, 'input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.perplexity.ai/v1/agent', (string) $request->getUri() );
            $this->assertEquals( 'perplexity/sonar', $body['model'] );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
            $this->assertArrayNotHasKey( 'text', $body );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"text":"a cat"}']]]]] )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'input_image', $content[1]['type'] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"name":"Jane"}']]]]] )
            ->structure( 'Extract', $schema, [], ['temperature' => 0.2, 'effort' => 'low', 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.2, $body['temperature'] );
            $this->assertEquals( 'low', $body['effort'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( [
                'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello world']]]],
                'usage' => ['total_tokens' => 10, 'input_tokens' => 5, 'output_tokens' => 5]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.perplexity.ai/v1/agent', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'perplexity/sonar', $body['model'] );
            $this->assertEquals( 'fast', $body['effort'] );
            $this->assertEquals( 'Say hello', $body['input'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['input'] );
            $this->assertArrayNotHasKey( 'stream', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteWithReasoningDisabled() : void
    {
        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]]] )
            ->withReasoning( false )
            ->write( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( ['effort' => 'minimal'], $body['reasoning'] );
        } );
    }


    public function testWriteToolLoopDropsSearchResults() : void
    {
        $tool = Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] );

        $this->response( [
            'status' => 'completed',
            'output' => [
                ['type' => 'search_results', 'results' => [['id' => 1, 'url' => 'https://example.com']]],
                ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ping', 'arguments' => '{}'],
            ],
        ] );

        $response = $this->response( [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Done']]]],
        ] );

        $response = $response->withTools( [$tool] )->ensure( 'write' )->write( 'Ping the tool' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        $input = json_decode( $requests[1]->getBody()->getContents(), true )['input'];

        $this->assertCount( 3, $input );
        $this->assertEquals( 'function_call', $input[1]['type'] );
        $this->assertEquals( 'function_call_output', $input[2]['type'] );
        $this->assertEquals( 'pong', $input[2]['output'] );
        $this->assertEquals( 'Done', $response->text() );
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( [
                'status' => 'completed',
                'output' => [
                    ['type' => 'search_results', 'results' => [
                        ['id' => 1, 'title' => 'Source 1', 'url' => 'https://example.com/source1', 'snippet' => 'snippet'],
                        ['id' => 2, 'url' => 'https://example.com/source2'],
                    ]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'The answer is 42']]],
                ],
            ] )
            ->write( 'What is the answer?' );

        $citations = $response->citations();
        $this->assertCount( 2, $citations );
        $this->assertEquals( 'Source 1', $citations[0]->title() );
        $this->assertEquals( 'https://example.com/source1', $citations[0]->url() );
        $this->assertNull( $citations[0]->text() );
        $this->assertNull( $citations[0]->source() );
        $this->assertNull( $citations[1]->title() );
        $this->assertEquals( 'https://example.com/source2', $citations[1]->url() );
        $this->assertEquals( 'The answer is 42', $response->text() );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'An image of a cat']]]]] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'input_text', $content[0]['type'] );
            $this->assertEquals( 'input_image', $content[1]['type'] );
            $this->assertStringStartsWith( 'data:image/png;base64,', $content[1]['image_url'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithOptions() : void
    {
        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'result']]]]] )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'reasoning' => ['effort' => 'high'], 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertEquals( ['effort' => 'high'], $body['reasoning'] );
            $this->assertEquals( 100, $body['max_output_tokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Bonjour']]]]] )
            ->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 1, $body['input'] );
            $this->assertEquals( 'Always respond in French', $body['instructions'] );
        } );
    }


    public function testWriteWithWebSearchProviderTool() : void
    {
        $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ok']]]]] )
            ->withTools( [Tools::provider( 'web_search' )->with( ['filters' => ['search_recency_filter' => 'week'], 'max_results' => 5, 'unknown' => 'x'] )] )
            ->ensure( 'write' )
            ->write( 'search?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( [
                'type' => 'web_search',
                'filters' => ['search_recency_filter' => 'week'],
                'max_results' => 5,
            ], $body['tools'][0] );
        } );
    }


    public function testWriteWithoutCitations() : void
    {
        $response = $this->prisma( 'text', 'perplexity', ['api_key' => 'test'] )
            ->response( ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello']]]]] )
            ->write( 'Say hello' );

        $this->assertEmpty( $response->citations() );
    }
}
