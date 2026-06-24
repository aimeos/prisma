<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MistralTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'mistral', [] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => '{"name":"John","age":30}'
                    ]
                ]],
                'usage' => ['total_tokens' => 15, 'prompt_tokens' => 10, 'completion_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.mistral.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'mistral-large-latest', $body['model'] );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
            $this->assertArrayHasKey( 'schema', $body['response_format']['json_schema'] );
            $this->assertArrayHasKey( 'strict', $body['response_format']['json_schema'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( '{"name":"John","age":30}', $response->text() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => '{"text":"a cat"}'
                    ]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'image_url', $content[0]['type'] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => '{"name":"Jane"}'
                    ]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->structure( 'Extract', $schema, [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testStructuredWithTools() : void
    {
        $schema = Schema::for( 'person', ['name' => Schema::string()] );
        $tool = \Aimeos\Prisma\Tools::make( 'lookup', 'Looks up a person', Schema::for( 'lookup', [] ), fn() => 'found' );

        $provider = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'lookup', 'arguments' => '{}']]],
                    ],
                ]],
            ] );
        $this->response( [
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"name":"John"}']]],
            'usage' => ['total_tokens' => 10],
        ] );

        $response = $provider->withTools( [$tool] )
            ->ensure( 'structure' )
            ->structure( 'Extract person', $schema );

        // Mistral can't combine response_format with tools, so the schema is embedded instead
        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertArrayHasKey( 'tools', $body );
            $this->assertArrayNotHasKey( 'response_format', $body );
        } );

        $this->assertEquals( ['name' => 'John'], $response->structured() );
        $this->assertCount( 1, $response->steps() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
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
            $this->assertEquals( 'https://api.mistral.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'mistral-large-latest', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( ['message' => 'Bad request'], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteToolChoiceAnyMapped() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'Done']]],
                'usage' => ['total_tokens' => 7]
            ] );

        $response->withTools( [$tool] )
            ->withToolChoice( 'any' )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // Mistral also accepts "any" for forcing tool use
            $this->assertEquals( 'any', $body['tool_choice'] );
        } );
    }


    public function testWriteToolChoiceRequiredMapped() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'Done']]],
                'usage' => ['total_tokens' => 7]
            ] );

        $response->withTools( [$tool] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // Mistral supports "required" for forcing tool use
            $this->assertEquals( 'required', $body['tool_choice'] );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
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


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
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


    public function testStreamWithProviderToolsIterable() : void
    {
        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] );

        $this->response( ['id' => 'agent_123'] );
        $this->response( [
            'choices' => [['message' => ['content' => 'Search result'], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 10]
        ] );

        $response = $this->provider()->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->ensure( 'stream' )
            ->stream( 'Search for something' );

        $chunks = [];

        foreach( $response->stream() as $chunk ) {
            $chunks[] = $chunk;
        }

        // the non-streamable Agents path still delivers its answer through stream()
        $this->assertSame( ['Search result'], $chunks );
        $this->assertEquals( 'Search result', $response->text() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testStreamWithProviderToolsErrorIsEager() : void
    {
        // the Agents API is not streamable, but an HTTP/auth error must still surface at the
        // stream() call (like every other provider), not later when the response is iterated
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Invalid API key']], [], 401 )
            ->ensure( 'stream' );

        $this->provider()->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->stream( 'Search for something' );
    }


    public function testStreamWithProviderToolsKeepsFalsyContent() : void
    {
        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] );

        $this->response( ['id' => 'agent_123'] );
        $this->response( [
            'choices' => [['message' => ['content' => '0'], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 1]
        ] );

        $response = $this->provider()->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->ensure( 'stream' )
            ->stream( 'Answer with just zero' );

        $chunks = [];

        foreach( $response->stream() as $chunk ) {
            $chunks[] = $chunk;
        }

        // "0" is a valid answer and must not be dropped by a truthiness check
        $this->assertSame( ['0'], $chunks );
        $this->assertSame( '0', $response->text() );
    }


    public function testStreamWithProviderToolsSurfacesRateLimit() : void
    {
        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] );

        $this->response( ['id' => 'agent_123'] );
        $this->response( [
            'choices' => [['message' => ['content' => 'Search result'], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 10]
        ], ['x-ratelimit-remaining' => '42'] );

        $response = $this->provider()->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->ensure( 'stream' )
            ->stream( 'Search for something' );

        // the Agents API runs eagerly, so the rate limit from its response is surfaced before the
        // stream is drained - like every SSE streaming path, not dropped as it was previously
        $this->assertSame( 42, $response->rateLimit()?->remaining() );
    }


    public function testWriteWithProviderTools() : void
    {
        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] );

        $this->response( ['id' => 'agent_123'] );
        $this->response( [
            'choices' => [[
                'message' => [
                    'content' => 'Search result'
                ]
            ]],
            'usage' => ['total_tokens' => 10, 'prompt_tokens' => 5, 'completion_tokens' => 5]
        ] );

        $this->provider()->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->write( 'Search for something' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        // First request: create agent
        $agentBody = json_decode( $requests[0]->getBody()->getContents(), true );
        $this->assertStringContainsString( '/v1/agents', (string) $requests[0]->getUri() );
        $this->assertArrayHasKey( 'tools', $agentBody );

        $hasWebSearch = false;
        foreach( $agentBody['tools'] as $tool ) {
            if( ( $tool['type'] ?? '' ) === 'web_search' ) {
                $hasWebSearch = true;
            }
        }
        $this->assertTrue( $hasWebSearch );

        // Second request: start conversation
        $convBody = json_decode( $requests[1]->getBody()->getContents(), true );
        $this->assertStringContainsString( '/v1/agents/conversations', (string) $requests[1]->getUri() );
        $this->assertEquals( 'agent_123', $convBody['agent_id'] );
    }


    public function testWriteWithReasoningEffort() : void
    {
        $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'ok']]], 'usage' => ['total_tokens' => 5]] )
            ->ensure( 'write' )
            ->write( 'hi', [], ['reasoning_effort' => 'high', 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'high', $body['reasoning_effort'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
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


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'mistral', ['api_key' => 'test'] )
            ->response( [
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'model' => 'mistral-embed',
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['Hello world'], 512 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.mistral.ai/v1/embeddings', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'mistral-embed', $body['model'] );
            $this->assertEquals( ['Hello world'], $body['input'] );
            $this->assertEquals( 512, $body['output_dimension'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertEquals( 5, $response->usage()['used'] );
    }
}
