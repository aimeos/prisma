<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AnthropicTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'anthropic', [] );
    }


    public function testOutputCombinesTexts() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'text' => 'world'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ] )
            ->ensure( 'write' )
            ->write( 'hi' );

        // text() returns the first entry, output() concatenates every collected text
        $this->assertEquals( 'Hello ', $response->text() );
        $this->assertEquals( 'Hello world', $response->output() );
    }


    public function testStream() : void
    {
        // Built from native arrays via the shipped SSE helper instead of a hand-escaped string.
        $deltas = [];

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->streamResponse( [
                ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]],
                ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
                ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']]],
                ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world']]],
                ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
                ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 7]]],
                ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.anthropic.com/v1/messages', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['stream'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 12, $response->usage()['used'] );
    }


    public function testStreamCitations() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->streamResponse( [
                ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude', 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]],
                ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
                ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Paris']]],
                ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => ['document_title' => 'Geo', 'cited_text' => 'Paris is the capital']]]],
                ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
                ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 3]]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'capital of France?' );

        $this->assertEquals( 'Paris', $response->text() );
        $this->assertCount( 1, $response->citations() );
        $this->assertEquals( 'msg_1', $response->meta()['id'] );
    }


    public function testStreamError() : void
    {
        $this->expectException( PrismaException::class );

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->streamResponse( [
                ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5]]]],
                ['event' => 'error', 'data' => ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'hi' );

        foreach( $response->stream() as $chunk ) {}
    }


    public function testStreamWithTools() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        // turn 1 streams a tool_use block, turn 2 streams the final text after the tool ran
        $provider = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->streamResponse( [
                ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]],
                ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'c1', 'name' => 'ping', 'input' => (object) []]]],
                ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']]],
                ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
                ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]]],
                ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
            ] );
        $this->streamResponse( [
            ['event' => 'message_start', 'data' => ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]],
            ['event' => 'content_block_start', 'data' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['event' => 'content_block_delta', 'data' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Done']]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ] );

        $chunks = [];
        $response = $provider->withTools( [$tool] )
            ->ensure( 'stream' )
            ->stream( 'ping it' );

        foreach( $response->stream() as $chunk ) {
            $chunks[] = $chunk instanceof \Aimeos\Prisma\Tools\Step
                ? ['name' => $chunk->name(), 'done' => $chunk->done(), 'result' => $chunk->result()]
                : $chunk;
        }

        // the tool is announced (done=false), then completed (done=true) before the final text delta
        $this->assertSame( ['name' => 'ping', 'done' => false, 'result' => ''], $chunks[0] );
        $this->assertSame( ['name' => 'ping', 'done' => true, 'result' => 'pong'], $chunks[1] );
        $this->assertSame( 'Done', $chunks[2] );

        $this->assertEquals( 'Done', $response->text() );
        $this->assertCount( 1, $response->steps() );
        $this->assertCount( 2, $this->requests() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => '{"name":"John","age":30}'
                ]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.anthropic.com/v1/messages', (string) $request->getUri() );
            $this->assertEquals( 'claude-opus-4-8', $body['model'] );
            $this->assertEquals( 'json_schema', $body['output_config']['format']['type'] );
            $this->assertArrayHasKey( 'schema', $body['output_config']['format'] );
            $this->assertFalse( $body['output_config']['format']['schema']['additionalProperties'] );
            $this->assertArrayNotHasKey( 'citations', $body );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( '{"name":"John","age":30}', $response->text() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredModeJsonOverridesNative() : void
    {
        // A simple schema would use native output_config automatically; mode=json forces prompt JSON mode.
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => '{"name":"Jane"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema, [], ['mode' => 'json'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );

            // No native structured output; the schema is embedded in the prompt instead.
            $this->assertArrayNotHasKey( 'output_config', $body );
            $this->assertStringContainsString( 'matching this JSON schema', $body['messages'][0]['content'][0]['text'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testStructuredNullableEnum() : void
    {
        $schema = Schema::for( 'block', [
            'align' => Schema::string()->enum( ['start', 'center', 'end'] )->nullable(),
        ] );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => '{"align":"start"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Pick alignment', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $align = $body['output_config']['format']['schema']['properties']['align'];

            // Nullable enum is sent as anyOf with a dedicated null branch, never as a
            // "type" array combined with "enum".
            $this->assertArrayNotHasKey( 'type', $align );
            $this->assertArrayNotHasKey( 'enum', $align );
            $this->assertEquals( [
                ['enum' => ['start', 'center', 'end']],
                ['type' => 'null'],
            ], $align['anyOf'] );
        } );
    }


    public function testStructuredStripsUnsupportedConstraints() : void
    {
        $schema = Schema::for( 'block', [
            'tags' => Schema::array()->min( 2 )->max( 5 ),
            'title' => Schema::string()->min( 3 )->max( 100 ),
        ] );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => '{"tags":["a","b"],"title":"hi there"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Build block', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $props = $body['output_config']['format']['schema']['properties'];

            // maxItems dropped, minItems clamped to the supported value 1.
            $this->assertArrayNotHasKey( 'maxItems', $props['tags'] );
            $this->assertEquals( 1, $props['tags']['minItems'] );

            // String length constraints dropped entirely.
            $this->assertArrayNotHasKey( 'minLength', $props['title'] );
            $this->assertArrayNotHasKey( 'maxLength', $props['title'] );
        } );
    }


    public function testStructuredUsesStrictWhenSchemaFits() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'note' => Schema::string()->nullable(),
        ] );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => '{"name":"John"}']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['output_config']['format']['type'] );
        } );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => '{"text":"a cat"}'
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'image', $content[0]['type'] );
            $this->assertEquals( 'text', $content[1]['type'] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => '{"name":"Jane"}'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
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
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Hello world'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.anthropic.com/v1/messages', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-api-key' ) );
            $this->assertEquals( '2023-06-01', $request->getHeaderLine( 'anthropic-version' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'claude-opus-4-8', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertEquals( 4096, $body['max_tokens'] );
            $this->assertArrayNotHasKey( 'system', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 8, $response->usage()['used'] );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteImageMimeWinsOverPdfExtension() : void
    {
        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'a cat']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] )
            ->ensure( 'write' )
            // explicit image mime must win even though the URL path ends in ".pdf"
            ->write( 'Describe', [Image::fromUrl( 'https://example.com/photo.pdf', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'image', $body['messages'][0]['content'][0]['type'] );
        } );
    }


    public function testWriteMaxStepsKeepsText() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [
                    ['type' => 'text', 'text' => 'Working on it'],
                    ['type' => 'tool_use', 'id' => 'c1', 'name' => 'ping', 'input' => []],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] );
        $this->response( [
            'content' => [['type' => 'tool_use', 'id' => 'c2', 'name' => 'ping', 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
        ] );

        $response = $provider->withTools( [$tool] )
            ->withMaxSteps( 2 )
            ->ensure( 'write' )
            ->write( 'go' );

        // the final step (capped by maxSteps) is tool-only; the earlier step's text must survive
        $this->assertEquals( 'Working on it', $response->text() );
        $this->assertCount( 2, $this->requests() );
    }


    public function testWritePauseTurnResumes() : void
    {
        $provider = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'Let me look that up.']],
                'stop_reason' => 'pause_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ] );
        $this->response( [
            'content' => [['type' => 'text', 'text' => 'The answer is 42.']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 6, 'output_tokens' => 4],
        ] );

        $response = $provider->ensure( 'write' )->write( 'What is the answer?' );

        // a pause_turn is resumed with another request instead of ending with a partial answer
        $this->assertCount( 2, $this->requests() );
        $this->assertEquals( 'The answer is 42.', $response->text() );
    }


    public function testWriteScalarToolInputNormalized() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'ping', 'input' => 'not-an-object']],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2],
            ] );
        $this->response( [
            'content' => [['type' => 'text', 'text' => 'done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 6, 'output_tokens' => 2],
        ] );

        // a scalar tool_use "input" must not crash; it is normalized to empty arguments
        $response = $provider->withTools( [$tool] )->ensure( 'write' )->write( 'Ping' );

        $this->assertEquals( [], $response->steps()[0]->arguments() );
        $this->assertEquals( 'pong', $response->steps()[0]->result() );
    }


    public function testWriteToolLoopEmptyInputIsObject() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'ping',
            'Returns pong',
            Schema::for( 'ping', [] ),
            fn() => 'pong'
        );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] );

        // First response: model calls the tool without arguments ("input": {})
        $this->response( [
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'ping',
                'input' => [],
            ]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
        ] );

        // Second response: model finishes after receiving the tool result
        $response = $this->response( [
            'content' => [[
                'type' => 'text',
                'text' => 'Done'
            ]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2]
        ] );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        // The second request must resend the assistant tool_use block with an
        // object input, not a JSON array, or Anthropic rejects it.
        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        $body = $requests[1]->getBody()->getContents();
        $this->assertStringContainsString( '"input":{}', $body );

        $decoded = json_decode( $body, true );
        $this->assertEquals( 'assistant', $decoded['messages'][1]['role'] );
        $this->assertEquals( 'tool_use', $decoded['messages'][1]['content'][0]['type'] );
        $this->assertSame( [], $decoded['messages'][1]['content'][0]['input'] );
    }


    public function testWriteToolLoopPreservesCallOrder() : void
    {
        // alpha runs sequentially, beta concurrently; without order preservation
        // execTools would return beta before alpha and mismatch the call order.
        $alpha = \Aimeos\Prisma\Tools::make( 'alpha', 'A', Schema::for( 'alpha', [] ), fn() => 'A' );
        $beta = \Aimeos\Prisma\Tools::make( 'beta', 'B', Schema::for( 'beta', [] ), fn() => 'B' )->concurrent();

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] );

        // Model calls alpha first, then beta, in one step
        $this->response( [
            'content' => [
                ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'alpha', 'input' => (object) []],
                ['type' => 'tool_use', 'id' => 'call_2', 'name' => 'beta', 'input' => (object) []]
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
        ] );

        $response = $this->response( [
            'content' => [['type' => 'text', 'text' => 'Done']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 2]
        ] );

        $response->withTools( [$alpha, $beta] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Call both tools' );

        $body = json_decode( $this->requests()[1]->getBody()->getContents(), true );

        // tool_result blocks must follow the model's call order: call_1 then call_2
        $results = $body['messages'][2]['content'];
        $this->assertEquals( 'call_1', $results[0]['tool_use_id'] );
        $this->assertEquals( 'call_2', $results[1]['tool_use_id'] );
    }


    public function testWriteWithAdaptiveThinking() : void
    {
        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ] )
            ->withThinkingBudget( 2048 )
            ->ensure( 'write' )
            ->write( 'think hard', [], ['thinking' => ['type' => 'adaptive'], 'effort' => 'high'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );

            // an explicit adaptive thinking option takes precedence over the budget fallback
            $this->assertEquals( ['type' => 'adaptive'], $body['thinking'] );
            $this->assertEquals( 'high', $body['effort'] );
        } );
    }




    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'The grass is green.',
                    'citations' => [[
                        'type' => 'char_location',
                        'cited_text' => 'The grass is green and lush.',
                        'document_title' => 'Nature Guide',
                        'document_index' => 0,
                        'start_char_index' => 0,
                        'end_char_index' => 27
                    ], [
                        'type' => 'char_location',
                        'cited_text' => 'Green is the color of nature.',
                        'document_title' => 'Color Theory',
                        'document_index' => 1,
                        'start_char_index' => 0,
                        'end_char_index' => 29
                    ]]
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->write( 'Describe grass', [], ['citations' => true] );

        $citations = $response->citations();
        $this->assertCount( 2, $citations );
        $this->assertEquals( 'Nature Guide', $citations[0]->title() );
        $this->assertNull( $citations[0]->url() );
        $this->assertNull( $citations[0]->text() );
        $this->assertEquals( 'The grass is green and lush.', $citations[0]->source() );
        $this->assertEquals( 'Color Theory', $citations[1]->title() );
        $this->assertEquals( 'Green is the color of nature.', $citations[1]->source() );
    }


    public function testWriteWithEagerInputStreaming() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' )
            ->with( ['eager_input_streaming' => true] );

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            ] )
            ->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'ping' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['tools'][0]['eager_input_streaming'] );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'An image of a cat'
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['messages'][0]['content'] );
            $this->assertEquals( 'image', $body['messages'][0]['content'][0]['type'] );
            $this->assertEquals( 'base64', $body['messages'][0]['content'][0]['source']['type'] );
            $this->assertEquals( 'text', $body['messages'][0]['content'][1]['type'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithImageUrl() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'a cat']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromUrl( 'https://example.com/cat.png', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $source = $body['messages'][0]['content'][0]['source'];

            // an image stays an image block, referenced by URL rather than inlined as base64
            $this->assertEquals( 'image', $body['messages'][0]['content'][0]['type'] );
            $this->assertEquals( 'url', $source['type'] );
            $this->assertEquals( 'https://example.com/cat.png', $source['url'] );
            $this->assertArrayNotHasKey( 'data', $source );
        } );

        $this->assertEquals( 'a cat', $response->text() );
    }


    public function testWriteWithMessages() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[ 'type' => 'text', 'text' => 'Blue' ]],
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

            $this->assertEquals( 'Sounds good, why?', $body['messages'][2]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Blue', $response->text() );
    }


    public function testWriteWithMultipleProviderTools() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'result'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] );

        $response->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' ), \Aimeos\Prisma\Tools::provider( 'code_execution' ), \Aimeos\Prisma\Tools::provider( 'web_fetch' )] )
            ->write( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $tools = $body['tools'];
            $this->assertCount( 3, $tools );
            $this->assertEquals( 'web_search_20250305', $tools[0]['type'] );
            $this->assertEquals( 'code_execution_20250825', $tools[1]['type'] );
            $this->assertEquals( 'web_fetch_20250910', $tools[2]['type'] );
        } );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'result'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2]
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


    public function testWriteWithPdfUrl() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'a report']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] )
            ->ensure( 'write' )
            ->write( 'Summarize this', [\Aimeos\Prisma\Files\File::fromUrl( 'https://example.com/r.pdf', 'application/pdf' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $block = $body['messages'][0]['content'][0];

            // a PDF uses a document block, not an image block
            $this->assertEquals( 'document', $block['type'] );
            $this->assertEquals( 'url', $block['source']['type'] );
        } );

        $this->assertEquals( 'a report', $response->text() );
    }


    public function testWriteWithPdfUrlByExtension() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [['type' => 'text', 'text' => 'a report']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] )
            ->ensure( 'write' )
            // mime not detected as a PDF, but the ".pdf" path still selects a document block
            ->write( 'Summarize this', [\Aimeos\Prisma\Files\File::fromUrl( 'https://example.com/r.pdf?token=1', 'application/octet-stream' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $block = $body['messages'][0]['content'][0];

            $this->assertEquals( 'document', $block['type'] );
            $this->assertEquals( 'url', $block['source']['type'] );
        } );

        $this->assertEquals( 'a report', $response->text() );
    }


    public function testWriteWithProviderToolOptions() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'result'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] );

        $response->withTools( [
                \Aimeos\Prisma\Tools::provider( 'web_search' )->max( 5 ),
            ] )
            ->write( 'Search for something' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $providerTool = end( $body['tools'] );
            $this->assertEquals( 'web_search_20250305', $providerTool['type'] );
            $this->assertEquals( 'web_search', $providerTool['name'] );
            $this->assertEquals( 5, $providerTool['max_uses'] );
        } );
    }


    public function testWriteWithProviderTools() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Search result'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3]
            ] );

        $response->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->write( 'Search for something' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertArrayHasKey( 'tools', $body );

            $providerTool = end( $body['tools'] );
            $this->assertEquals( 'web_search_20250305', $providerTool['type'] );
            $this->assertEquals( 'web_search', $providerTool['name'] );
        } );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Bonjour'
                ]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 2]
            ] );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Always respond in French', $body['system'] );
        } );
    }


    public function testWriteWithoutCitations() : void
    {
        $response = $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( [
                'content' => [[
                    'type' => 'text',
                    'text' => 'Hello'
                ]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 1]
            ] )
            ->write( 'Say hello' );

        $this->assertEmpty( $response->citations() );
    }
}
