<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OllamaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testStream() : void
    {
        $deltas = [];

        $response = $this->prisma( 'text', 'ollama', [] )
            ->streamResponse( [
                ['choices' => [['delta' => ['content' => 'Hello']]]],
                ['choices' => [['delta' => ['content' => ' world']]]],
                ['choices' => [['delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 10]],
                ['data' => '[DONE]'],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'http://localhost:11434/v1/chat/completions', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'llama4', $body['model'] );
            $this->assertTrue( $body['stream'] );
            $this->assertTrue( $body['stream_options']['include_usage'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testStreamError() : void
    {
        $this->expectException( \Aimeos\Prisma\Exceptions\PrismaException::class );

        $response = $this->prisma( 'text', 'ollama', [] )
            ->streamResponse( [
                ['choices' => [['delta' => ['content' => 'Hel']]]],
                ['error' => ['message' => 'server overloaded', 'type' => 'server_error']],
            ] )
            ->ensure( 'stream' )
            ->stream( 'hi' );

        foreach( $response->stream() as $chunk ) {}
    }


    public function testStreamReasoning() : void
    {
        $deltas = [];

        $response = $this->prisma( 'text', 'ollama', [] )
            ->streamResponse( [
                ['choices' => [['delta' => ['reasoning_content' => 'thinking...']]]],
                ['choices' => [['delta' => ['content' => 'Answer']]]],
                ['choices' => [['delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 5]],
                ['data' => '[DONE]'],
            ] )
            ->ensure( 'stream' )
            ->stream( 'hi' );

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        // reasoning is not streamed to the callback but is kept on the response meta
        $this->assertSame( ['Answer'], $deltas );
        $this->assertEquals( 'Answer', $response->text() );
        $this->assertEquals( 'thinking...', $response->meta()['thinking'] );
    }


    public function testStreamWithTools() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'ollama', [] )
            ->streamResponse( [
                ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'ping', 'arguments' => '']]]]]]],
                ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]]]]],
                ['choices' => [['delta' => (object) [], 'finish_reason' => 'tool_calls']]],
                ['data' => '[DONE]'],
            ] );
        $this->streamResponse( [
            ['choices' => [['delta' => ['content' => 'pong!']]]],
            ['choices' => [['delta' => (object) [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 12]],
            ['data' => '[DONE]'],
        ] );

        $chunks = [];

        $response = $provider->withTools( [$tool] )
            ->ensure( 'stream' )
            ->stream( 'Ping the tool' );

        foreach( $response->stream() as $chunk ) {
            // Read Step state inside the loop: the same instance is reused for
            // both the started and completed notifications.
            $chunks[] = $chunk instanceof \Aimeos\Prisma\Tools\Step
                ? ['name' => $chunk->name(), 'done' => $chunk->done(), 'result' => $chunk->result()]
                : $chunk;
        }

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


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'ollama', [] )
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
            $this->assertEquals( 'http://localhost:11434/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'llama4', $body['model'] );
            $this->assertEquals( 'json_object', $body['response_format']['type'] );
            $this->assertStringContainsString( 'Extract person info', $body['messages'][0]['content'][0]['text'] );
            $this->assertStringContainsString( '"type"', $body['messages'][0]['content'][0]['text'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'ollama', [] )
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
            $this->assertEquals( 'json_object', $body['response_format']['type'] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'ollama', [] )
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
            $this->assertEquals( 'json_object', $body['response_format']['type'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'ollama', [] )
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
            $this->assertEquals( 'http://localhost:11434/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'llama4', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteWithApiKey() : void
    {
        $response = $this->prisma( 'text', 'ollama', ['api_key' => 'test-key'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Hello'
                    ]
                ]]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertStringContainsString( 'Bearer test-key', $request->getHeaderLine( 'Authorization' ) );
        } );
    }


    public function testWriteWithCustomUrl() : void
    {
        $response = $this->prisma( 'text', 'ollama', ['url' => 'http://gpu-server:11434'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Hello'
                    ]
                ]]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'http://gpu-server:11434/v1/chat/completions', (string) $request->getUri() );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'ollama', [] )
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
        $response = $this->prisma( 'text', 'ollama', [] )
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
        $response = $this->prisma( 'text', 'ollama', [] )
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
        $response = $this->prisma( 'text', 'ollama', [] )
            ->response( [
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'model' => 'nomic-embed-text',
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['Hello world'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'http://localhost:11434/v1/embeddings', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'nomic-embed-text', $body['model'] );
            $this->assertEquals( ['Hello world'], $body['input'] );
            $this->assertArrayNotHasKey( 'dimensions', $body );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertEquals( 5, $response->usage()['used'] );
    }
}
