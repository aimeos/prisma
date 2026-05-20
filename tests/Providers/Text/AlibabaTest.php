<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AlibabaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Hello world'
                    ]
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
                'request_id' => 'req-123'
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'qwen-vl-plus', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 8, $response->usage()['used'] );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'An image of a cat'
                    ]
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
            ] )
            ->ensure( 'write' )
            ->write( 'Describe this image', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertCount( 2, $content );
            $this->assertEquals( 'image_url', $content[0]['type'] );
            $this->assertArrayHasKey( 'image_url', $content[0] );
            $this->assertEquals( 'text', $content[1]['type'] );
        } );

        $this->assertEquals( 'An image of a cat', $response->text() );
    }


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Bonjour'
                    ]
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]
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
        $response = $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'result'
                    ]
                ]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2, 'total_tokens' => 7]
            ] )
            ->ensure( 'write' )
            ->write( 'prompt', [], ['temperature' => 0.5, 'max_tokens' => 100, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertEquals( 100, $body['max_tokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( ['message' => 'Bad request'], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteWithProviderTools() : void
    {
        $response = $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'choices' => [[
                    'message' => [
                        'content' => 'Search result'
                    ]
                ]],
                'usage' => ['total_tokens' => 10]
            ] );

        $response->withTools( [\Aimeos\Prisma\Tools::provider( 'web_search' )] )
            ->write( 'Search for something' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertTrue( $body['enable_search'] );
        } );
    }


    public function testWriteWithTools() : void
    {
        $tool = Tools::make( 'get_weather', 'Get weather for a city', Schema::fromArray( 'get_weather', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ] ), fn() => 'sunny' );

        $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] );
        $this->response( [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => 'The weather is sunny'
                ]
            ]],
            'usage' => ['total_tokens' => 10]
        ] );

        $this->provider()->withTools( [$tool] )
            ->write( 'What is the weather?' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertArrayHasKey( 'tools', $body );
            $this->assertCount( 1, $body['tools'] );
            $this->assertEquals( 'function', $body['tools'][0]['type'] );
            $this->assertEquals( 'get_weather', $body['tools'][0]['function']['name'] );
            $this->assertArrayHasKey( 'tool_choice', $body );
        } );
    }


    public function testWriteWithToolLoop() : void
    {
        $tool = Tools::make( 'get_weather', 'Get weather for a city', Schema::fromArray( 'get_weather', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ] ), fn() => 'sunny and 25°C' );

        $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] );

        $this->response( [
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => '{"city":"Berlin"}'
                        ]
                    ]]
                ]
            ]],
            'usage' => ['total_tokens' => 10]
        ] );

        $this->response( [
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'content' => 'It is sunny and 25°C in Berlin'
                ]
            ]],
            'usage' => ['total_tokens' => 20]
        ] );

        $response = $this->provider()->withTools( [$tool] )
            ->write( 'What is the weather in Berlin?' );

        $requests = $this->requests();
        $this->assertCount( 2, $requests );

        $secondBody = json_decode( $requests[1]->getBody()->getContents(), true );
        $messages = $secondBody['messages'];
        $toolMsg = end( $messages );
        $this->assertEquals( 'tool', $toolMsg['role'] );
        $this->assertEquals( 'call_123', $toolMsg['tool_call_id'] );
        $this->assertStringContainsString( 'sunny and 25°C', $toolMsg['content'] );

        $this->assertEquals( 'It is sunny and 25°C in Berlin', $response->text() );
        $this->assertCount( 1, $response->steps() );
        $this->assertEquals( 'get_weather', $response->steps()[0]->name() );
    }


    public function testWriteWithToolChoice() : void
    {
        $tool = Tools::make( 'get_weather', 'Get weather', Schema::fromArray( 'get_weather', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
        ] ), fn() => '' );

        $this->prisma( 'text', 'alibaba', ['api_key' => 'test'] );
        $this->response( [
            'choices' => [[
                'message' => ['content' => 'result']
            ]],
            'usage' => ['total_tokens' => 5]
        ] );

        $this->provider()->withTools( [$tool] )
            ->withToolChoice( 'required' )
            ->write( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'required', $body['tool_choice'] );
        } );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'alibaba', [] );
    }
}
