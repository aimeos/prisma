<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
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


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'alibaba', [] );
    }
}
