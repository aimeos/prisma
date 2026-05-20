<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
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
            ->write( 'prompt', [], ['temperature' => 0.5, 'max_output_tokens' => 100, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['temperature'] );
            $this->assertEquals( 100, $body['max_output_tokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'openai', [] );
    }
}
