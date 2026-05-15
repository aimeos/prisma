<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AnthropicTest extends TestCase
{
    use MakesPrismaRequests;


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
            $this->assertEquals( 'claude-sonnet-4-20250514', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertEquals( 4096, $body['max_tokens'] );
            $this->assertArrayNotHasKey( 'system', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 8, $response->usage()['used'] );
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

        $this->prisma( 'text', 'anthropic', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'anthropic', [] );
    }
}
