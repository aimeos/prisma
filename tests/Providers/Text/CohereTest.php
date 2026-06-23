<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class CohereTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'cohere', [] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
            'age' => Schema::integer(),
        ] );

        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => '{"name":"John","age":30}'
                    ]]
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => ['tokens' => ['input_tokens' => 10, 'output_tokens' => 5]]
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract person info', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://api.cohere.ai/v2/chat', (string) $request->getUri() );
            $this->assertEquals( 'json_object', $body['response_format']['type'] );
            $this->assertArrayHasKey( 'json_schema', $body['response_format'] );
            $this->assertFalse( $body['response_format']['json_schema']['additionalProperties'] );
            // Cohere requires every object to declare at least one required field
            $this->assertEquals( ['name', 'age'], $body['response_format']['json_schema']['required'] );
        } );

        $this->assertEquals( ['name' => 'John', 'age' => 30], $response->structured() );
        $this->assertEquals( '{"name":"John","age":30}', $response->text() );
        $this->assertEquals( 15, $response->usage()['used'] );
    }


    public function testStructuredWithFiles() : void
    {
        $schema = Schema::for( 'description', [
            'text' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => '{"text":"a cat"}'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 10, 'output_tokens' => 5]]
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


    public function testStructuredWithOptions() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string(),
        ] );

        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => '{"name":"Jane"}'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 3]]
            ] )
            ->structure( 'Extract', $schema, [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hello world'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 3]]
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.cohere.ai/v2/chat', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'command-a-plus-05-2026', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 8, $response->usage()['used'] );
    }


    public function testWriteError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( ['message' => 'Bad request'], status: 400, reason: 'Bad Request' )
            ->ensure( 'write' )
            ->write( 'prompt' );
    }


    public function testWriteToolChoiceAutoOmitted() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Done']]],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 2]]
            ] );

        $response->withTools( [$tool] )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // auto is the default and is not a valid Cohere value, so the field is omitted
            $this->assertArrayHasKey( 'tools', $body );
            $this->assertArrayNotHasKey( 'tool_choice', $body );
        } );
    }


    public function testWriteToolChoiceRequiredMapped() : void
    {
        $tool = \Aimeos\Prisma\Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Done']]],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 2]]
            ] );

        $response->withTools( [$tool] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQ )
            ->ensure( 'write' )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // Cohere requires uppercase "REQUIRED", not the internal "required"
            $this->assertEquals( 'REQUIRED', $body['tool_choice'] );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'An image of a cat'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 10, 'output_tokens' => 5]]
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


    public function testWriteWithOptions() : void
    {
        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'result'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 2]]
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


    public function testWriteWithSystemPrompt() : void
    {
        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Bonjour'
                    ]]
                ],
                'usage' => ['tokens' => ['input_tokens' => 5, 'output_tokens' => 2]]
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


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'cohere', ['api_key' => 'test'] )
            ->response( [
                'id' => 'da6e531f',
                'embeddings' => [
                    'float' => [[0.1, 0.2, 0.3]],
                ],
                'meta' => [
                    'billed_units' => ['input_tokens' => 4],
                ],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['Hello world'], 256 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.cohere.ai/v2/embed', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'embed-v4.0', $body['model'] );
            $this->assertEquals( ['Hello world'], $body['texts'] );
            $this->assertEquals( 'search_document', $body['input_type'] );
            $this->assertEquals( 256, $body['output_dimension'] );
            $this->assertEquals( ['float'], $body['embedding_types'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertEquals( 4, $response->usage()['used'] );
    }
}
