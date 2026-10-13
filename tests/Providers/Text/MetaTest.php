<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MetaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'meta', [] );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]],
                ['data' => ['choices' => [['delta' => ['content' => ' world']]]]],
                ['data' => ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 10]]],
                ['data' => '[DONE]'],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/chat/completions', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'muse-spark-1.3', $body['model'] );
            $this->assertTrue( $body['stream'] );
            $this->assertTrue( $body['stream_options']['include_usage'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] )->strict();

        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => '{"name":"John","age":30}'],
                ]],
                'usage' => ['total_tokens' => 15],
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $format = $body['response_format'];

            $this->assertEquals( 'https://api.meta.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'json_schema', $format['type'] );
            $this->assertEquals( 'person', $format['json_schema']['name'] );
            $this->assertTrue( $format['json_schema']['strict'] );
            $this->assertFalse( $format['json_schema']['schema']['additionalProperties'] );
            $this->assertEquals( ['name', 'age'], $format['json_schema']['schema']['required'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
    }


    public function testStructuredWithProviderTools() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"name":"John"}'
                    ]]
                ]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 15]
            ] )
            ->withTools( [Tools::provider( 'web_search' )] )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.meta.ai/v1/responses', (string) $request->getUri() );
            $this->assertEquals( 'json_schema', $body['text']['format']['type'] );
            $this->assertEquals( 'person', $body['text']['format']['name'] );
            $this->assertEquals( [['type' => 'web_search']], $body['tools'] );
        } );

        $this->assertEquals( ['name' => 'John'], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => 'Hello world'],
                ]],
                'usage' => ['total_tokens' => 10, 'prompt_tokens' => 5, 'completion_tokens' => 5],
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'muse-spark-1.3', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteReasoningDisabled() : void
    {
        $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'result']]]] )
            ->withReasoning( false )
            ->write( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'minimal', $body['reasoning_effort'] );
        } );
    }


    public function testWriteToolChoice() : void
    {
        $tool = Tools::make( 'ping', 'Returns pong', Schema::for( 'ping' ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'ping', 'arguments' => '{}'],
                        ]],
                    ],
                ]],
            ] );
        $this->response( [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'pong!'],
            ]],
        ] );

        $response = $provider->withTools( [$tool] )
            ->withToolChoice( Base::REQUIRED )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $requests = $this->requests();
        $first = json_decode( $requests[0]->getBody()->getContents(), true );
        $second = json_decode( $requests[1]->getBody()->getContents(), true );

        // Meta only supports "auto", so forced choices are omitted
        $this->assertArrayHasKey( 'tools', $first );
        $this->assertArrayNotHasKey( 'tool_choice', $first );
        $this->assertEquals( 'auto', $second['tool_choice'] );
        $this->assertEquals( 'pong', $second['messages'][2]['content'] );
        $this->assertEquals( 'pong!', $response->text() );
        $this->assertEquals( 'pong', $response->steps()[0]->result() );
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
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
            ->withTools( [Tools::provider( 'web_search' )] )
            ->write( 'What is the answer?' );

        $citations = $response->citations();
        $this->assertCount( 1, $citations );
        $this->assertEquals( 'The Answer', $citations[0]->title() );
        $this->assertEquals( 'https://example.com/answer', $citations[0]->url() );
        $this->assertEquals( 'The answer is 42.', $citations[0]->text() );
    }


    public function testWriteWithImage() : void
    {
        $response = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'A cat']]]] )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];

            $this->assertEquals( 'image_url', $content[0]['type'] );
            $this->assertStringStartsWith( 'data:image/png;base64,', $content[0]['image_url']['url'] );
            $this->assertEquals( 'text', $content[1]['type'] );
        } );

        $this->assertEquals( 'A cat', $response->text() );
    }


    public function testWriteWithOptions() : void
    {
        $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'result']]]] )
            ->withMaxTokens( 100 )
            ->withThinkingBudget( 9000 )
            ->write( 'prompt', [], [
                'stop' => ['END'],
                'temperature' => 0.5,
                'unknown' => 'ignored',
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );

            $this->assertEquals( 100, $body['max_completion_tokens'] );
            $this->assertEquals( 'high', $body['reasoning_effort'] );
            $this->assertEquals( ['END'], $body['stop'] );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertArrayNotHasKey( 'max_tokens', $body );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithProviderTools() : void
    {
        $result = $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Search result'
                    ]]
                ]],
                'usage' => ['total_tokens' => 10, 'input_tokens' => 5, 'output_tokens' => 5]
            ] )
            ->withTools( [Tools::provider( 'web_search' )->with( ['search_context_size' => 'high', 'blocked_domains' => ['x.com']] )] )
            ->withSystemPrompt( 'Be helpful' )
            ->withMaxTokens( 200 )
            ->withThinkingBudget( 1000 )
            ->write( 'Search for something' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.meta.ai/v1/responses', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertArrayHasKey( 'input', $body );
            $this->assertArrayNotHasKey( 'messages', $body );
            $this->assertEquals( [['type' => 'web_search', 'search_context_size' => 'high']], $body['tools'] );
            $this->assertEquals( 'Be helpful', $body['instructions'] );
            $this->assertEquals( 200, $body['max_output_tokens'] );
            $this->assertEquals( ['effort' => 'low'], $body['reasoning'] );
        } );

        $this->assertEquals( 'Search result', $result->text() );
    }


    public function testWriteWithVideo() : void
    {
        $this->prisma( 'text', 'meta', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'A flower']]]] )
            ->write( 'Describe this video', [Video::fromBinary( 'MP4', 'video/mp4' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];

            $this->assertEquals( 'video_url', $content[0]['type'] );
            $this->assertStringStartsWith( 'data:video/mp4;base64,', $content[0]['video_url']['url'] );
            $this->assertEquals( 'text', $content[1]['type'] );
        } );
    }
}
