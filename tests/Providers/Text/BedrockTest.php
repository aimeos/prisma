<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BedrockTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWriteWithMessages() : void
    {
        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [ 'message' => [ 'role' => 'assistant', 'content' => [[ 'text' => 'Blue' ]] ] ],
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
            $this->assertEquals( 'How about blue?', $body['messages'][1]['content'][0]['text'] );

            $this->assertEquals( 'Sounds good, why?', $body['messages'][2]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Blue', $response->text() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => 'Hello world'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 3]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/global.amazon.nova-2-lite-v1:0/converse', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
            $this->assertArrayNotHasKey( 'system', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 8, $response->usage()['used'] );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => 'An image of a cat'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertArrayHasKey( 'image', $content[0] );
            $this->assertArrayHasKey( 'text', $content[1] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => 'Bonjour'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 2]
            ] );

        $response->withSystemPrompt( 'Always respond in French' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Always respond in French', $body['system'][0]['text'] );
        } );
    }


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => 'result'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 2]
            ] )
            ->ensure( 'write' )
            ->withMaxTokens( 100 )
            ->write( 'prompt', [], ['temperature' => 0.5, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['inferenceConfig']['temperature'] );
            $this->assertEquals( 100, $body['inferenceConfig']['maxTokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body['inferenceConfig'] );
        } );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => '{"name":"John","age":30}'
                        ]]
                    ]
                ],
                'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertStringContainsString( '/model/global.amazon.nova-2-lite-v1:0/converse', (string) $request->getUri() );
            $prompt = $body['messages'][0]['content'][0]['text'];
            $this->assertStringContainsString( 'Extract person info', $prompt );
            $this->assertStringContainsString( 'JSON', $prompt );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => '{"name":"Jane"}'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 3]
            ] )
            ->structure( 'Extract', $schema, [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.2, $body['inferenceConfig']['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body['inferenceConfig'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => '{"text":"a cat"}'
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5]
            ] )
            ->structure( 'Describe', $schema, [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertArrayHasKey( 'image', $content[0] );
        } );

        $this->assertEquals( ['text' => 'a cat'], $response->structured() );
    }


    public function testStructuredStripsCodeBlocks() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [[
                            'text' => "```json\n{\"name\":\"John\"}\n```"
                        ]]
                    ]
                ],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 3]
            ] )
            ->structure( 'Extract', $schema );

        $this->assertEquals( ['name' => 'John'], $response->structured() );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( ['message' => 'Bad request'], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }




    public function testWriteToolLoopEmptyInputIsObject() : void
    {
        $tool = \Aimeos\Prisma\Tools::make(
            'ping',
            'Returns pong',
            Schema::for( 'ping', [] ),
            fn() => 'pong'
        );

        $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] );

        // First response: model calls the tool without arguments ("input": {})
        $this->response( [
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'toolUse' => ['toolUseId' => 'tool_1', 'name' => 'ping', 'input' => (object) []]
                    ]]
                ]
            ],
            'usage' => ['inputTokens' => 5, 'outputTokens' => 3]
        ] );

        // Second response: model finishes after receiving the tool result
        $response = $this->response( [
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['text' => 'Done']]
                ]
            ],
            'usage' => ['inputTokens' => 5, 'outputTokens' => 2]
        ] );

        $response->withTools( [$tool] )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        // The resent assistant turn must carry an object input, not a JSON array
        $body = $requests[1]->getBody()->getContents();
        $this->assertStringContainsString( '"input":{}', $body );

        $decoded = json_decode( $body, true );
        $this->assertSame( [], $decoded['messages'][1]['content'][0]['toolUse']['input'] );
    }


    public function testWriteToolChoiceRequiredMapped() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Done']]]],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 2]
            ] );

        $response->withTools( [$tool] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $raw = $request->getBody()->getContents();
            // forced tool use maps to Converse "any" as an object, not an array
            $this->assertStringContainsString( '"any":{}', $raw );
            $this->assertSame( [], json_decode( $raw, true )['toolConfig']['toolChoice']['any'] );
        } );
    }


    public function testWriteToolChoiceAutoOmitted() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'Done']]]],
                'usage' => ['inputTokens' => 5, 'outputTokens' => 2]
            ] );

        $response->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // auto is the default, so no toolChoice is sent
            $this->assertArrayHasKey( 'tools', $body['toolConfig'] );
            $this->assertArrayNotHasKey( 'toolChoice', $body['toolConfig'] );
        } );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'bedrock', [] );
    }
}
