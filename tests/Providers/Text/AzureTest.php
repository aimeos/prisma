<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AzureTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['resource' => 'r'] );
    }


    public function testNoResource() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['api_key' => 'test'] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'r'] )
            ->response( [
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"name":"Jane"}']]],
                'usage' => ['total_tokens' => 5],
            ] )
            ->ensure( 'structure' )
            ->structure( 'Extract', $schema );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
        } );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( ['choices' => [['message' => ['content' => 'Hello']]], 'usage' => ['total_tokens' => 5]] )
            ->ensure( 'write' )
            ->write( 'Say hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://myres.openai.azure.com/openai/v1/chat/completions',
                (string) $request->getUri()
            );
            $this->assertEquals( 'test', $request->getHeaderLine( 'api-key' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'gpt-5.6-sol', $body['model'] );
        } );

        $this->assertEquals( 'Hello', $response->text() );
    }


    public function testWriteWithUrlAndDeployment() : void
    {
        $this->prisma( 'text', 'azure', ['api_key' => 'test', 'url' => 'https://custom.example.com/'] )
            ->response( ['choices' => [['message' => ['content' => 'ok']]]] )
            ->model( 'my-deployment' )
            ->ensure( 'write' )
            ->write( 'hi' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://custom.example.com/openai/v1/chat/completions',
                (string) $request->getUri()
            );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'my-deployment', $body['model'] );
        } );
    }


    public function testTranslate() : void
    {
        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( [
                ['translations' => [['text' => 'Hallo', 'to' => 'de']]],
                ['translations' => [['text' => 'Welt', 'to' => 'de']]],
            ] )
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://myres.cognitiveservices.azure.com/translator/text/v3.0/translate?api-version=3.0&to=de',
                (string) $request->getUri()
            );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'Ocp-Apim-Subscription-Key' ) );
            $this->assertFalse( $request->hasHeader( 'Ocp-Apim-Subscription-Region' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( [['Text' => 'Hello'], ['Text' => 'World']], $body );
        } );

        $this->assertEquals( ['Hallo', 'Welt'], $response->texts() );
    }


    public function testTranslateError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( ['error' => ['code' => 401000, 'message' => 'Unauthorized']], status: 401, reason: 'Unauthorized' )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );
    }


    public function testTranslateNoTranslatorUrl() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'azure', ['api_key' => 'test', 'url' => 'https://custom.example.com'] )
            ->response( [] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );
    }


    public function testTranslateWithOptions() : void
    {
        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( [['translations' => [['text' => '<b>Bonjour</b>', 'to' => 'fr']]]] )
            ->ensure( 'translate' )
            ->translate( ['<b>Hello</b>'], 'fr', 'en', null, ['textType' => 'html', 'allowFallback' => false, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            parse_str( $request->getUri()->getQuery(), $query );

            $this->assertEquals( 'en', $query['from'] );
            $this->assertEquals( 'fr', $query['to'] );
            $this->assertEquals( 'html', $query['textType'] );
            $this->assertEquals( 'false', $query['allowFallback'] );
            $this->assertArrayNotHasKey( 'unknown', $query );
        } );

        $this->assertEquals( ['<b>Bonjour</b>'], $response->texts() );
    }


    public function testTranslateWithTranslatorUrl() : void
    {
        $this->prisma( 'text', 'azure', ['api_key' => 'test', 'translator_url' => 'https://api.cognitive.microsofttranslator.com/', 'region' => 'westeurope'] )
            ->response( [['translations' => [['text' => 'Hallo', 'to' => 'de']]]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://api.cognitive.microsofttranslator.com/translate?api-version=3.0&to=de',
                (string) $request->getUri()
            );
            $this->assertEquals( 'westeurope', $request->getHeaderLine( 'Ocp-Apim-Subscription-Region' ) );
        } );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'azure', ['api_key' => 'test', 'resource' => 'myres'] )
            ->response( [
                'object' => 'list',
                'data' => [
                    ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( ['Hello world'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://myres.openai.azure.com/openai/v1/embeddings',
                (string) $request->getUri()
            );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( ['Hello world'], $body['input'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertEquals( 5, $response->usage()['used'] );
    }
}
