<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class DeeplTest extends TestCase
{
    use MakesPrismaRequests;


    public function testTranslate() : void
    {
        $response = $this->prisma( 'text', 'deepl', ['api_key' => 'test'] )
            ->response( ['translations' => [['text' => 'Hallo'], ['text' => 'Welt']]] )
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api-free.deepl.com/v2/translate', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'DeepL-Auth-Key test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( ['Hello', 'World'], $body['text'] );
            $this->assertEquals( 'DE', $body['target_lang'] );
            $this->assertArrayNotHasKey( 'source_lang', $body );
            $this->assertArrayNotHasKey( 'context', $body );
        } );

        $this->assertEquals( ['Hallo', 'Welt'], $response->texts() );
    }


    public function testTranslateWithFrom() : void
    {
        $response = $this->prisma( 'text', 'deepl', ['api_key' => 'test'] )
            ->response( ['translations' => [['text' => 'Bonjour']]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'fr', 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'EN', $body['source_lang'] );
            $this->assertEquals( 'FR', $body['target_lang'] );
        } );

        $this->assertEquals( ['Bonjour'], $response->texts() );
    }


    public function testTranslateWithContext() : void
    {
        $response = $this->prisma( 'text', 'deepl', ['api_key' => 'test'] )
            ->response( ['translations' => [['text' => 'Bank']]] )
            ->ensure( 'translate' )
            ->translate( ['bank'], 'de', null, 'river bank' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'river bank', $body['context'] );
            $this->assertArrayNotHasKey( 'source_lang', $body );
        } );

        $this->assertEquals( ['Bank'], $response->texts() );
    }


    public function testTranslateWithOptions() : void
    {
        $response = $this->prisma( 'text', 'deepl', ['api_key' => 'test'] )
            ->response( ['translations' => [['text' => 'Hallo']]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de', null, null, ['formality' => 'more', 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'more', $body['formality'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['Hallo'], $response->texts() );
    }


    public function testTranslateWithCustomUrl() : void
    {
        $response = $this->prisma( 'text', 'deepl', ['api_key' => 'test', 'url' => 'https://api.deepl.com'] )
            ->response( ['translations' => [['text' => 'Hallo']]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.deepl.com/v2/translate', (string) $request->getUri() );
        } );
    }


    public function testTranslateError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'deepl', ['api_key' => 'test'] )
            ->response( ['message' => 'Forbidden'], status: 403, reason: 'Forbidden' )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'deepl', [] );
    }
}
