<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GroqTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'groq', [] );
    }


    public function testStream() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":10}}\n\n"
            . "data: [DONE]\n\n";

        $deltas = [];

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'stream' )
            ->stream( 'Say hello', [], [], function( $chunk ) use ( &$deltas ) {
                $deltas[] = $chunk;
            } );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.groq.com/openai/v1/chat/completions', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['stream'] );
            $this->assertTrue( $body['stream_options']['include_usage'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testStreamError() : void
    {
        $this->expectException( PrismaException::class );

        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"error\":{\"message\":\"server overloaded\",\"type\":\"server_error\"}}\n\n";

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'stream' )
            ->stream( 'hi', [], [], function( $chunk ) {} );
    }


    public function testStreamMeta() : void
    {
        $sse = "data: {\"id\":\"chatcmpl-1\",\"model\":\"openai/gpt-oss-120b\",\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":3}}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'stream' )
            ->stream( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( 'Hi', $response->text() );
        $this->assertEquals( 'chatcmpl-1', $response->meta()['id'] );
        $this->assertEquals( 'openai/gpt-oss-120b', $response->meta()['model'] );
    }


    public function testStreamReasoning() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking...\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Answer\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":5}}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'stream' )
            ->stream( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( 'Answer', $response->text() );
        $this->assertEquals( 'thinking...', $response->meta()['thinking'] );
    }


    public function testStreamRejectsInvalidToolArgs() : void
    {
        $called = false;
        $tool = \Aimeos\Prisma\Tools::make(
            'get_weather', 'Returns the weather for a city',
            Schema::for( 'weather', ['city' => Schema::string()->required()] ),
            function( $args ) use ( &$called ) { $called = true; return 'sunny'; }
        );

        $turn1 = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"c1\",\"function\":{\"name\":\"get_weather\",\"arguments\":\"{}\"}}]}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n"
            . "data: [DONE]\n\n";

        $turn2 = "data: {\"choices\":[{\"delta\":{\"content\":\"Which city?\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n\n";

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $turn1, ['Content-Type' => 'text/event-stream'] );
        $this->response( $turn2, ['Content-Type' => 'text/event-stream'] );

        $results = [];

        $response = $provider->withTools( [$tool] )
            ->ensure( 'stream' )
            ->stream( 'weather?', [], [], function( $chunk ) use ( &$results ) {
                if( $chunk instanceof \Aimeos\Prisma\Tools\Step && $chunk->done() ) {
                    $results[] = $chunk->result();
                }
            } );

        // the handler must not run when the arguments fail schema validation
        $this->assertFalse( $called );
        $this->assertNotEmpty( $results );
        $this->assertStringContainsString( 'invalid arguments', $results[0] );
        $this->assertStringContainsString( 'city', $results[0] );
        $this->assertEquals( 'Which city?', $response->text() );
    }


    public function testStreamWithTools() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $turn1 = "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"ping\",\"arguments\":\"\"}}]}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"function\":{\"arguments\":\"{}\"}}]}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"tool_calls\"}]}\n\n"
            . "data: [DONE]\n\n";

        $turn2 = "data: {\"choices\":[{\"delta\":{\"content\":\"pong!\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":12}}\n\n"
            . "data: [DONE]\n\n";

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $turn1, ['Content-Type' => 'text/event-stream'] );
        $this->response( $turn2, ['Content-Type' => 'text/event-stream'] );

        $chunks = [];

        $response = $provider->withTools( [$tool] )
            ->ensure( 'stream' )
            ->stream( 'Ping the tool', [], [], function( $chunk ) use ( &$chunks ) {
                // Read Step state inside the callback: the same instance is reused for
                // both the started and completed notifications.
                $chunks[] = $chunk instanceof \Aimeos\Prisma\Tools\Step
                    ? ['name' => $chunk->name(), 'done' => $chunk->done(), 'result' => $chunk->result()]
                    : $chunk;
            } );

        // call started (no result), call completed (result), then the final text delta
        $this->assertSame( 'ping', $chunks[0]['name'] );
        $this->assertFalse( $chunks[0]['done'] );
        $this->assertSame( 'ping', $chunks[1]['name'] );
        $this->assertTrue( $chunks[1]['done'] );
        $this->assertSame( 'pong', $chunks[1]['result'] );
        $this->assertSame( 'pong!', $chunks[2] );

        $this->assertEquals( 'pong!', $response->text() );
        $this->assertCount( 1, $response->steps() );
        $this->assertEquals( 'pong', $response->steps()[0]->result() );
        $this->assertCount( 2, $this->requests() );
    }


    public function testStreamZeroContent() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"0\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'stream' )
            ->stream( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( '0', $response->text() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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
            $this->assertEquals( 'https://api.groq.com/openai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'openai/gpt-oss-120b', $body['model'] );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
            $this->assertFalse( $body['response_format']['json_schema']['schema']['additionalProperties'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredModeStructured() : void
    {
        // mode=structured selects native strict mode regardless of schema depth.
        $schema = Schema::for( 'deep', [
            'a' => Schema::object( ['b' => Schema::object( ['c' => Schema::object( [
                'd' => Schema::object( ['e' => Schema::object( ['f' => Schema::string()] )] ),
            ] )] )] ),
        ] );

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"a":{"b":{"c":{"d":{"e":{"f":"x"}}}}}}']]],
                'usage' => ['total_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema, [], ['mode' => 'structured'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertArrayHasKey( 'json_schema', $body['response_format'] );
            $this->assertArrayNotHasKey( 'mode', $body );
        } );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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
            $this->assertEquals( 'https://api.groq.com/openai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'openai/gpt-oss-120b', $body['model'] );
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

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteRelaxesScalarToolTypes() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'calc', 'Adds two numbers',
            Schema::for( 'calc', ['a' => Schema::integer(), 'flag' => Schema::boolean()] ),
            fn() => '3'
        );

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'done']]]] )
            ->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'add' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $props = $body['tools'][0]['function']['parameters']['properties'];

            // Groq rejects scalar params it may receive as strings, so they are wrapped in anyOf
            $this->assertArrayNotHasKey( 'type', $props['a'] );
            $this->assertEquals( [['type' => 'integer'], ['type' => 'string']], $props['a']['anyOf'] );
            $this->assertEquals( [['type' => 'boolean'], ['type' => 'string']], $props['flag']['anyOf'] );
        } );
    }


    public function testWriteSanitizesToolArguments() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'get_weather', 'Returns the weather for a city',
            Schema::for( 'get_weather', ['city' => Schema::string()] ),
            fn( $args ) => 'sunny in ' . ( $args['city'] ?? '?' )
        );

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'id' => 'c1',
                            // a raw control char in the arguments would normally break json_decode
                            'function' => ['name' => 'get_weather', 'arguments' => "{\"city\":\"NYC\x00\"}"],
                        ]],
                    ],
                ]],
            ] );
        $this->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'done']]]] );

        $response = $provider->withTools( [$tool] )->ensure( 'write' )->write( 'weather?' );

        $this->assertEquals( 'sunny in NYC', $response->steps()[0]->result() );
    }


    public function testWriteSplitsMangledToolName() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'get_weather', 'Returns the weather for a city',
            Schema::for( 'get_weather', ['city' => Schema::string()] ),
            fn( $args ) => 'sunny in ' . ( $args['city'] ?? '?' )
        );

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'function' => ['name' => 'get_weather,{"city":"NYC"}', 'arguments' => '{}'],
                        ]],
                    ],
                ]],
            ] );
        $this->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'done']]]] );

        $response = $provider->withTools( [$tool] )->ensure( 'write' )->write( 'Weather?' );

        // the mangled "name,{json}" is split so the call resolves to the registered tool
        $this->assertCount( 1, $response->steps() );
        $this->assertEquals( 'get_weather', $response->steps()[0]->name() );
        $this->assertEquals( 'sunny in NYC', $response->steps()[0]->result() );
    }


    public function testWriteToolApprovalAllowed() : void
    {
        $called = false;
        $tool = \Aimeos\Prisma\Tools::make(
            'ping', 'Returns pong', Schema::for( 'ping', [] ),
            function() use ( &$called ) { $called = true; return 'pong'; }
        )->with( ['needs_approval' => true] );

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => ['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'ping', 'arguments' => '{}']]]],
                ]],
            ] );
        $this->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'pong!']]]] );

        $response = $provider->withTools( [$tool] )
            ->withToolApproval( fn() => true )
            ->ensure( 'write' )
            ->write( 'ping' );

        $this->assertTrue( $called );
        $this->assertEquals( 'pong', $response->steps()[0]->result() );
    }


    public function testWriteToolApprovalDenied() : void
    {
        $called = false;
        $tool = \Aimeos\Prisma\Tools::make(
            'delete_account', 'Deletes the account',
            Schema::for( 'delete_account', [] ),
            function() use ( &$called ) { $called = true; return 'deleted'; }
        )->with( ['needs_approval' => true] );

        $provider = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => ['role' => 'assistant', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'delete_account', 'arguments' => '{}']]]],
                ]],
            ] );
        $this->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Okay, cancelled.']]]] );

        $response = $provider->withTools( [$tool] )
            ->withToolApproval( fn( $name, $args ) => false )
            ->ensure( 'write' )
            ->write( 'Delete my account' );

        // a denied approval must skip the handler and return a denial to the model
        $this->assertFalse( $called );
        $this->assertStringContainsString( 'denied', $response->steps()[0]->result() );
        $this->assertEquals( 'Okay, cancelled.', $response->text() );
    }


    public function testWriteToolChoiceNoneOmitted() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'Done']]],
                'usage' => ['total_tokens' => 7]
            ] );

        $response->withTools( [$tool] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::NONE )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // Groq does not support "none", so it is omitted instead of erroring
            $this->assertArrayHasKey( 'tools', $body );
            $this->assertArrayNotHasKey( 'tool_choice', $body );
        } );
    }


    public function testWriteToolChoiceRequiredMapped() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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
            $this->assertEquals( 'required', $body['tool_choice'] );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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


    public function testWriteWithMessages() : void
    {
        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[ 'message' => [ 'content' => 'Blue' ] ]],
            ] )
            ->withMessages( [
                ['role' => 'user', 'content' => 'Recommend a colour'],
                ['role' => 'assistant', 'content' => 'How about blue?'],
            ] )
            ->write( 'Sounds good, why?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );

            $this->assertCount( 3, $body['messages'] );

            $this->assertEquals( 'user', $body['messages'][0]['role'] );
            $this->assertEquals( 'Recommend a colour', $body['messages'][0]['content'][0]['text'] );

            $this->assertEquals( 'assistant', $body['messages'][1]['role'] );
            $this->assertEquals( 'How about blue?', $body['messages'][1]['content'] );

            $this->assertEquals( 'user', $body['messages'][2]['role'] );
            $this->assertEquals( 'Sounds good, why?', $body['messages'][2]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Blue', $response->text() );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
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
}
