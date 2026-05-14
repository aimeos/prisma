<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BedrockTest extends TestCase
{
    use MakesPrismaRequests;


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
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-pro-v1:0/converse', (string) $request->getUri() );
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
            ->write( 'prompt', [], ['temperature' => 0.5, 'maxTokens' => 100, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.5, $body['inferenceConfig']['temperature'] );
            $this->assertEquals( 100, $body['inferenceConfig']['maxTokens'] );
            $this->assertArrayNotHasKey( 'unknown', $body['inferenceConfig'] );
        } );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'bedrock', ['api_key' => 'test'] )
            ->response( ['message' => 'Bad request'], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'bedrock', [] );
    }
}
