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
            $this->assertEquals( 'meta-llama/llama-4-scout-17b-16e-instruct', $body['model'] );
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
            $this->assertEquals( 'meta-llama/llama-4-scout-17b-16e-instruct', $body['model'] );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
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


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'groq', [] );
    }
}
