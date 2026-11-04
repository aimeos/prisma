<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class FalTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'fal', [] );
    }


    public function testStream() : void
    {
        $response = $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]],
                ['data' => ['choices' => [['delta' => ['content' => ' world']]]]],
                ['data' => ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 3]]],
                ['data' => '[DONE]'],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        $this->assertEquals( 'Hello world', $response->text() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://fal.run/openrouter/router/openai/v1/chat/completions', (string) $request->getUri() );
            $this->assertTrue( $body['stream'] );
        } );
    }


    public function testStructure() : void
    {
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"name":"Jane"}']]],
                'usage' => ['total_tokens' => 4],
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
        } );
    }


    public function testTranslate() : void
    {
        $response = $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( [
                'id' => 'gen-1',
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"texts":["Hallo","Welt"]}']]],
                'usage' => ['total_tokens' => 9],
            ] )
            ->ensure( 'translate' )
            ->translate( ['a' => 'Hello', 'b' => 'World'], 'de', 'en', 'Greeting', ['temperature' => 0.1, 'top_k' => 5] );

        $this->assertSame( ['Hallo', 'Welt'], $response->texts() );
        $this->assertSame( 9.0, $response->usage()['used'] );
        $this->assertSame( 'gen-1', $response->meta()['id'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $prompt = $body['messages'][0]['content'][0]['text'] ?? $body['messages'][0]['content'];
            $this->assertSame( 'https://fal.run/openrouter/router/openai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertSame( 0.1, $body['temperature'] );
            $this->assertArrayNotHasKey( 'top_k', $body );
            $this->assertStringContainsString( 'from the language of ISO code "en" into the language of ISO code "de"', $prompt );
            $this->assertStringContainsString( 'Context of the texts: Greeting', $prompt );
            $this->assertStringContainsString( '["Hello","World"]', $prompt );
        } );
    }


    public function testTranslateCountMismatch() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"texts":["Hallo"]}']]]] );

        $this->expectException( PrismaException::class );
        $this->provider()->translate( ['Hello', 'World'], 'de' );
    }


    public function testTranslateInvalidUtf8() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test'] );

        $this->expectException( \JsonException::class );
        $this->provider()->translate( ["\xB1"], 'de' );
    }


    public function testTranslateNoString() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"texts":["Hallo",null]}']]]] );

        $this->expectException( PrismaException::class );
        $this->provider()->translate( ['Hello', 'World'], 'de' );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( [
                'data' => [['embedding' => [0.1, 0.2]]],
                'usage' => ['total_tokens' => 2],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['hello'], 256 );

        $this->assertEquals( [[0.1, 0.2]], $response->vectors() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://fal.run/openrouter/router/openai/v1/embeddings', (string) $request->getUri() );
            $this->assertEquals( 'openai/text-embedding-3-small', $body['model'] );
            $this->assertEquals( 256, $body['dimensions'] );
        } );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'Hello world']]],
                'usage' => ['total_tokens' => 10],
            ] )
            ->ensure( 'write' )
            ->write( 'Say hello', [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertEquals( 'Hello world', $response->text() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://fal.run/openrouter/router/openai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'Key test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'openai/gpt-6-luna', $body['model'] );
            $this->assertEquals( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );
    }


    public function testWriteWithRunUrl() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test', 'run_url' => 'https://gateway.example.com/fal-run/'] )
            ->response( ['choices' => [['message' => ['content' => 'Hi']]]] )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://gateway.example.com/fal-run/openrouter/router/openai/v1/chat/completions', (string) $request->getUri() );
        } );
    }


    public function testWriteWithModelAndUrl() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test', 'url' => 'https://gateway.example.com/fal/'] )
            ->response( ['choices' => [['message' => ['content' => 'Hi']]]] )
            ->model( 'anthropic/claude-sonnet-5' )
            ->write( 'Say hello' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://gateway.example.com/fal/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'anthropic/claude-sonnet-5', $body['model'] );
        } );
    }


    public function testWriteWithFiles() : void
    {
        $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'A cat']]]] )
            ->write( 'Describe', [Image::fromUrl( 'https://example.com/cat.png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['messages'][0]['content'];
            $this->assertEquals( ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/cat.png']], $content[0] );
            $this->assertEquals( ['type' => 'text', 'text' => 'Describe'], $content[1] );
        } );
    }


    public function testWriteWithTools() : void
    {
        $tool = Tools::make( 'ping', 'Returns pong', Schema::for( 'ping', [] ), fn() => 'pong' );

        $this->prisma( 'text', 'fal', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'Done']]]] )
            ->withTools( [$tool, Tools::provider( 'web_search' )] )
            ->write( 'Ping the tool' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            // custom tools only, OpenRouter server tools aren't sent to fal
            $this->assertCount( 1, $body['tools'] );
            $this->assertEquals( 'ping', $body['tools'][0]['function']['name'] );
        } );
    }
}
