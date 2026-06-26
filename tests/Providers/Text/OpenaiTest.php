<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', [] );
    }


    public function testStream() : void
    {
        // consume the response as a pull generator (e.g. Laravel eventStream)
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
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
            $this->assertEquals( 'https://api.openai.com/v1/responses', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['stream'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 9, $response->usage()['used'] );
    }


    public function testStreamLazyDrain() : void
    {
        // neither a callback nor stream() iteration: the accessor must drain the stream
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['type' => 'response.output_text.delta', 'delta' => 'Hello world']],
                ['data' => ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello world']]]], 'usage' => ['total_tokens' => 9]]]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 9, $response->usage()['used'] );
    }


    public function testStreamErrorIsEager() : void
    {
        // an HTTP/auth error must surface at the stream() call, before the body is iterated
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Invalid API key']], [], 401 )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );
    }


    public function testStreamMidStreamErrorThenAccessorDoesNotCrash() : void
    {
        // first delta arrives, then a streamed error event aborts the producer mid-stream
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['type' => 'response.output_text.delta', 'delta' => 'Hello']],
                ['data' => ['error' => ['message' => 'mid-stream boom']]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        try {
            foreach( $response->stream() as $chunk ) {}
            $this->fail( 'expected a mid-stream error' );
        } catch ( PrismaException $e ) {
            $this->assertStringContainsString( 'mid-stream boom', $e->getMessage() );
        }

        // a later accessor must not re-enter the aborted generator (no secondary fatal)
        $this->assertNull( $response->text() );
        $this->assertTrue( $response->ready() );
    }


    public function testStreamEarlyBreakIsTerminal() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['type' => 'response.output_text.delta', 'delta' => 'Hello']],
                ['data' => ['type' => 'response.output_text.delta', 'delta' => ' world']],
                ['data' => ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hello world']]]], 'usage' => ['total_tokens' => 9]]]],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        $deltas = [];

        // stop consuming after the first delta
        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
            break;
        }

        // the stream is consumed once: stopping early leaves the response unassembled and a
        // later accessor neither restarts nor resumes it
        $this->assertSame( ['Hello'], $deltas );
        $this->assertNull( $response->text() );
    }


    public function testStreamWithTools() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['type' => 'response.created', 'response' => ['status' => 'in_progress']]],
                ['data' => ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'ping', 'arguments' => '{}']], 'usage' => ['total_tokens' => 3]]]],
            ] );
        $this->streamResponse( [
            ['data' => ['type' => 'response.output_text.delta', 'delta' => 'pong!']],
            ['data' => ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'pong!']]]], 'usage' => ['total_tokens' => 12]]]],
        ] );

        $response = $provider->withTools( [$tool] )
            ->ensure( 'stream' )
            ->stream( 'Ping the tool' );

        $chunks = [];

        foreach( $response->stream() as $chunk ) {
            // Snapshot Step state inside the loop: the same instance is reused for
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
            'address' => Schema::object( [
                'city' => Schema::string(),
            ] ),
            'meta' => Schema::object( [] ),
        ] );

        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"name":"John","age":30}'
                    ]]
                ]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 15, 'input_tokens' => 10, 'output_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.openai.com/v1/responses', (string) $request->getUri() );
            $this->assertEquals( 'gpt-5.5', $body['model'] );
            $this->assertEquals( 'json_schema', $body['text']['format']['type'] );
            $this->assertEquals( 'person', $body['text']['format']['name'] );
            $this->assertArrayHasKey( 'schema', $body['text']['format'] );
            $this->assertArrayHasKey( 'strict', $body['text']['format'] );
            $this->assertFalse( $body['text']['format']['schema']['additionalProperties'] );
            $this->assertFalse( $body['text']['format']['schema']['properties']['address']['additionalProperties'] );
            $this->assertFalse( $body['text']['format']['schema']['properties']['meta']['additionalProperties'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( '{"name":"John","age":30}', $response->text() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredModeJsonOverridesNative() : void
    {
        // A shallow schema would use native strict mode automatically; mode=json forces JSON mode.
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [['content' => [['type' => 'output_text', 'text' => '{"name":"Jane"}']]]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema, [], ['mode' => 'json'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_object', $body['text']['format']['type'] );
            $this->assertArrayNotHasKey( 'schema', $body['text']['format'] );
            // "mode" is not leaked into the request payload.
            $this->assertArrayNotHasKey( 'mode', $body );
            $last = end( $body['input'] );
            $block = end( $last['content'] );
            $this->assertStringContainsString( 'matching this JSON schema', $block['text'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testStructuredModeStructured() : void
    {
        // mode=structured selects native strict mode regardless of schema depth.
        $schema = Schema::for( 'deep', [
            'a' => Schema::object( ['b' => Schema::object( ['c' => Schema::object( [
                'd' => Schema::object( ['e' => Schema::object( ['f' => Schema::string()] )] ),
            ] )] )] ),
        ] );

        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [['content' => [['type' => 'output_text', 'text' => '{"a":{"b":{"c":{"d":{"e":{"f":"x"}}}}}}']]]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema, [], ['mode' => 'structured'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // Native strict mode selected by mode=structured.
            $this->assertEquals( 'json_schema', $body['text']['format']['type'] );
            $this->assertArrayHasKey( 'schema', $body['text']['format'] );
            $this->assertArrayNotHasKey( 'mode', $body );
        } );

        $this->assertEquals( ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'x']]]]]], $response->structured() );
    }


    public function testStructuredInvalidModeThrows() : void
    {
        $this->expectException( \Aimeos\Prisma\Exceptions\BadRequestException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )->provider()
            ->ensure( 'structure' )
            ->structure( 'Extract', Schema::for( 'p', ['name' => Schema::string()] ), [], ['mode' => 'bogus'] );
    }


    public function testStructuredNullableEnum() : void
    {
        $schema = Schema::for( 'block', [
            'align' => Schema::string()->enum( ['start', 'center', 'end'] )->nullable(),
        ] );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [['content' => [['type' => 'output_text', 'text' => '{"align":"start"}']]]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 2, 'input_tokens' => 1, 'output_tokens' => 1]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Pick alignment', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $align = $body['text']['format']['schema']['properties']['align'];

            // Standard JSON Schema nullable enum: "type" array plus null in the enum.
            $this->assertEquals( ['string', 'null'], $align['type'] );
            $this->assertEquals( ['start', 'center', 'end', null], $align['enum'] );
        } );
    }


    public function testStructuredStrictRequiresAllProperties() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer(),
            'address' => Schema::object( [
                'city' => Schema::string(),
            ] ),
        ] )->strict();

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"name":"John","age":30,"address":{"city":"NYC"}}'
                    ]]
                ]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 15]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['text']['format']['strict'] );
            $schema = $body['text']['format']['schema'];
            // strict mode requires every property listed as required, recursively
            $this->assertEquals( ['name', 'age', 'address'], $schema['required'] );
            $this->assertEquals( ['city'], $schema['properties']['address']['required'] );
            $this->assertFalse( $schema['additionalProperties'] );
            $this->assertFalse( $schema['properties']['address']['additionalProperties'] );
        } );
    }


    public function testStructuredWithAnyOf() : void
    {
        $schema = Schema::for( 'result', [
            'value' => Schema::anyOf( [
                Schema::string(),
                Schema::object( ['code' => Schema::integer()] ),
            ] ),
        ] );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [['type' => 'output_text', 'text' => '{"value":"ok"}']]
                ]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $value = $body['text']['format']['schema']['properties']['value'];
            $this->assertArrayNotHasKey( 'type', $value );
            $this->assertEquals( 'string', $value['anyOf'][0]['type'] );
            // object branches are closed recursively
            $this->assertFalse( $value['anyOf'][1]['additionalProperties'] );
        } );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"text":"a cat"}'
                    ]]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'input_text', $content[0]['type'] );
            $this->assertEquals( 'input_image', $content[1]['type'] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"name":"Jane"}'
                    ]]
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


    public function testStructuredWithRefAndDefs() : void
    {
        $schema = Schema::for( 'person', [
            'address' => Schema::ref( 'Address' )->required(),
        ] )->def( 'Address', Schema::object( [
            'city' => Schema::string()->required(),
        ] ) );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [['type' => 'output_text', 'text' => '{"address":{"city":"x"}}']]
                ]],
                'status' => 'completed',
                'usage' => ['total_tokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema->strict() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $json = $body['text']['format']['schema'];
            $this->assertEquals( '#/$defs/Address', $json['properties']['address']['$ref'] );
            // referenced definitions are closed and required recursively
            $this->assertFalse( $json['$defs']['Address']['additionalProperties'] );
            $this->assertEquals( ['city'], $json['$defs']['Address']['required'] );
        } );
    }


    public function testWithMessagesInvalidRole() : void
    {
        $this->expectException( \Aimeos\Prisma\Exceptions\BadRequestException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [] )
            ->withMessages( [['role' => 'system', 'content' => 'nope']] )
            ->write( 'hi' );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello world'
                    ]]
                ]],
                'usage' => ['total_tokens' => 10, 'input_tokens' => 5, 'output_tokens' => 5]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.openai.com/v1/responses', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-5.5', $body['model'] );
            $this->assertEquals( 'Say hello', $body['input'][0]['content'][0]['text'] );
            $this->assertArrayNotHasKey( 'instructions', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteNonStrictToolKeepsParameters() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'get_weather', 'Returns the weather',
            Schema::for( 'get_weather', ['city' => Schema::string()] ),
            fn() => 'sunny'
        );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]], 'status' => 'completed'] )
            ->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'weather?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // a non-strict tool schema is sent unchanged (no forced closing)
            $this->assertArrayNotHasKey( 'additionalProperties', $body['tools'][0]['parameters'] );
        } );
    }


    public function testWriteStrictToolClosesParameters() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'get_weather', 'Returns the weather',
            Schema::for( 'get_weather', ['city' => Schema::string()] )->strict(),
            fn() => 'sunny'
        );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['output' => [['content' => [['type' => 'output_text', 'text' => 'ok']]]], 'status' => 'completed'] )
            ->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'weather?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $params = $body['tools'][0]['parameters'];

            // strict tool params require additionalProperties:false and every property required
            $this->assertTrue( $body['tools'][0]['strict'] );
            $this->assertFalse( $params['additionalProperties'] );
            $this->assertEquals( ['city'], $params['required'] );
        } );
    }


    public function testWriteToolLoopResendsFunctionCallItems() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'ping',
            'Returns pong',
            Schema::for( 'ping', [] ),
            fn() => 'pong'
        );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] );

        // First response: model emits a function_call output item
        $this->response( [
            'output' => [[
                'type' => 'function_call',
                'call_id' => 'call_1',
                'name' => 'ping',
                'arguments' => '{}'
            ]],
            'status' => 'completed',
            'usage' => ['total_tokens' => 8]
        ] );

        // Second response: model finishes with text
        $response = $this->response( [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'Done'
                ]]
            ]],
            'status' => 'completed',
            'usage' => ['total_tokens' => 7]
        ] );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        $body = json_decode( $requests[1]->getBody()->getContents(), true );
        $input = $body['input'];

        // The function_call item must be resent as a top-level input item (not wrapped
        // in an assistant message's content), and the function_call_output must follow it.
        $this->assertEquals( 'Ping the tool', $input[0]['content'][0]['text'] );
        $this->assertEquals( 'function_call', $input[1]['type'] );
        $this->assertEquals( 'call_1', $input[1]['call_id'] );
        $this->assertEquals( 'function_call_output', $input[2]['type'] );
        $this->assertEquals( 'call_1', $input[2]['call_id'] );
        $this->assertEquals( 'pong', $input[2]['output'] );

        // No assistant message may carry a function_call inside its content
        foreach( $input as $item )
        {
            if( ( $item['role'] ?? '' ) === 'assistant' ) {
                foreach( $item['content'] ?? [] as $block ) {
                    $this->assertNotEquals( 'function_call', $block['type'] ?? '' );
                }
            }
        }
    }


    public function testWriteWithCitations() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'The capital of France is Paris.',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/france',
                            'title' => 'France Facts',
                            'start_index' => 0,
                            'end_index' => 31
                        ], [
                            'type' => 'url_citation',
                            'url' => 'https://example.com/paris',
                            'title' => 'Paris Guide',
                            'start_index' => 24,
                            'end_index' => 31
                        ]]
                    ]]
                ]],
                'usage' => ['total_tokens' => 10]
            ] )
            ->write( 'What is the capital of France?' );

        $citations = $response->citations();
        $this->assertCount( 2, $citations );
        $this->assertEquals( 'France Facts', $citations[0]->title() );
        $this->assertEquals( 'https://example.com/france', $citations[0]->url() );
        $this->assertEquals( 'The capital of France is Paris.', $citations[0]->text() );
        $this->assertNull( $citations[0]->source() );
        $this->assertEquals( 'Paris Guide', $citations[1]->title() );
        $this->assertEquals( ' Paris.', $citations[1]->text() );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'An image of a cat'
                    ]]
                ]]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertCount( 2, $body['input'][0]['content'] );
            $this->assertEquals( 'input_text', $body['input'][0]['content'][0]['type'] );
            $this->assertEquals( 'input_image', $body['input'][0]['content'][1]['type'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithMessages() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[ 'content' => [[ 'type' => 'output_text', 'text' => 'Blue' ]] ]],
            ] )
            ->withMessages( [
                ['role' => 'user', 'content' => 'What is in this image?', 'files' => [Image::fromBinary( 'PNG', 'image/png' )]],
                ['role' => 'assistant', 'content' => 'A bicycle.'],
            ] )
            ->write( 'What colour is it?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );

            $this->assertCount( 3, $body['input'] );

            $this->assertEquals( 'user', $body['input'][0]['role'] );
            $this->assertEquals( 'What is in this image?', $body['input'][0]['content'][0]['text'] );
            $this->assertEquals( 'input_image', $body['input'][0]['content'][1]['type'] );

            $this->assertEquals( 'assistant', $body['input'][1]['role'] );
            $this->assertEquals( 'output_text', $body['input'][1]['content'][0]['type'] );
            $this->assertEquals( 'A bicycle.', $body['input'][1]['content'][0]['text'] );

            $this->assertEquals( 'user', $body['input'][2]['role'] );
            $this->assertEquals( 'What colour is it?', $body['input'][2]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Blue', $response->text() );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'result'
                    ]]
                ]]
            ] )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertEquals( 100, $body['max_output_tokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Bonjour'
                    ]]
                ]]
            ] );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Always respond in French', $body['instructions'] );
        } );
    }


    public function testWriteWithoutCitations() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Hello'
                    ]]
                ]],
                'usage' => ['total_tokens' => 5]
            ] )
            ->write( 'Say hello' );

        $this->assertEmpty( $response->citations() );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( [
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['Hello world'], 256 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'https://api.openai.com/v1/embeddings', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'text-embedding-3-small', $body['model'] );
            $this->assertEquals( ['Hello world'], $body['input'] );
            $this->assertEquals( 256, $body['dimensions'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertEquals( [0.1, 0.2, 0.3], $response->first() );
        $this->assertEquals( 5, $response->usage()['used'] );
    }
}
