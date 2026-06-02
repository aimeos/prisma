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


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
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


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', [] );
    }
}
