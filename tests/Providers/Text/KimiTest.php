<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class KimiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'kimi', [] );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['choices' => [['delta' => ['reasoning_content' => 'Thinking...']]]]],
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
            $this->assertEquals( 'https://api.moonshot.ai/v1/chat/completions', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'kimi-k3', $body['model'] );
            $this->assertTrue( $body['stream'] );
            $this->assertTrue( $body['stream_options']['include_usage'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 'Thinking...', $response->meta()['thinking'] );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] )->strict();

        $response = $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
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

            $this->assertEquals( 'https://api.moonshot.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'json_schema', $format['type'] );
            $this->assertEquals( 'person', $format['json_schema']['name'] );
            $this->assertTrue( $format['json_schema']['strict'] );
            $this->assertFalse( $format['json_schema']['schema']['additionalProperties'] );
            $this->assertEquals( ['name', 'age'], $format['json_schema']['schema']['required'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => 'Hello world',
                        'reasoning_content' => 'A short greeting is enough.',
                    ],
                ]],
                'usage' => ['total_tokens' => 10, 'prompt_tokens' => 5, 'completion_tokens' => 5],
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.moonshot.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'kimi-k3', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( 'A short greeting is enough.', $response->meta()['thinking'] );
        $this->assertEquals( 10, $response->usage()['used'] );
    }


    public function testWritePreservesReasoningInToolLoop() : void
    {
        $tool = Tools::make( 'ping', 'Returns pong', Schema::for( 'ping' ), fn() => 'pong' );

        $provider = $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'reasoning_content' => 'I should use ping.',
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

        $this->assertEquals( 'required', $first['tool_choice'] );
        $this->assertEquals( 'auto', $second['tool_choice'] );
        $this->assertEquals( 'I should use ping.', $second['messages'][1]['reasoning_content'] );
        $this->assertEquals( 'pong', $second['messages'][2]['content'] );
        $this->assertEquals( 'pong!', $response->text() );
        $this->assertEquals( 'pong', $response->steps()[0]->result() );
    }


    public function testWriteUsesKimiOptions() : void
    {
        $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
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
            $this->assertEquals( 'max', $body['reasoning_effort'] );
            $this->assertEquals( ['END'], $body['stop'] );
            $this->assertArrayNotHasKey( 'max_tokens', $body );
            $this->assertArrayNotHasKey( 'temperature', $body );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithImage() : void
    {
        $response = $this->prisma( 'text', 'kimi', ['api_key' => 'test'] )
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
}
