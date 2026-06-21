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


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
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


    public function testChat() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":10}}\n\n"
            . "data: [DONE]\n\n";

        $deltas = [];

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'chat' )
            ->chat( 'Say hello', [], [], function( $chunk ) use ( &$deltas ) {
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


    public function testChatWithTools() : void
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
            ->ensure( 'chat' )
            ->chat( 'Ping the tool', [], [], function( $chunk ) use ( &$chunks ) {
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


    public function testChatError() : void
    {
        $this->expectException( PrismaException::class );

        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"error\":{\"message\":\"server overloaded\",\"type\":\"server_error\"}}\n\n";

        $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'chat' )
            ->chat( 'hi', [], [], function( $chunk ) {} );
    }


    public function testChatReasoning() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"thinking...\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{\"content\":\"Answer\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":5}}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'chat' )
            ->chat( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( 'Answer', $response->text() );
        $this->assertEquals( 'thinking...', $response->meta()['thinking'] );
    }


    public function testChatMeta() : void
    {
        $sse = "data: {\"id\":\"chatcmpl-1\",\"model\":\"openai/gpt-oss-120b\",\"choices\":[{\"delta\":{\"content\":\"Hi\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":3}}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'chat' )
            ->chat( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( 'Hi', $response->text() );
        $this->assertEquals( 'chatcmpl-1', $response->meta()['id'] );
        $this->assertEquals( 'openai/gpt-oss-120b', $response->meta()['model'] );
    }


    public function testChatZeroContent() : void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"0\"}}]}\n\n"
            . "data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n"
            . "data: [DONE]\n\n";

        $response = $this->prisma( 'text', 'groq', ['api_key' => 'test'] )
            ->response( $sse, ['Content-Type' => 'text/event-stream'] )
            ->ensure( 'chat' )
            ->chat( 'hi', [], [], function( $chunk ) {} );

        $this->assertEquals( '0', $response->text() );
    }


    public function testChatRejectsInvalidToolArgs() : void
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
            ->ensure( 'chat' )
            ->chat( 'weather?', [], [], function( $chunk ) use ( &$results ) {
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


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'groq', [] );
    }
}
