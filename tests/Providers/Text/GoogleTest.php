<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GoogleTest extends TestCase
{
    use MakesPrismaRequests;


    public function testTranslate() : void
    {
        $response = $this->prisma( 'text', 'google', ['api_key' => 'test'] )
            ->response( ['data' => ['translations' => [['translatedText' => 'Hallo'], ['translatedText' => 'Welt']]]] )
            ->ensure( 'translate' )
            ->translate( ['Hello', 'World'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://translation.googleapis.com/language/translate/v2?key=test', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( ['Hello', 'World'], $body['q'] );
            $this->assertEquals( 'de', $body['target'] );
            $this->assertEquals( 'text', $body['format'] );
            $this->assertArrayNotHasKey( 'source', $body );
        } );

        $this->assertEquals( ['Hallo', 'Welt'], $response->texts() );
    }


    public function testTranslateWithFrom() : void
    {
        $response = $this->prisma( 'text', 'google', ['api_key' => 'test'] )
            ->response( ['data' => ['translations' => [['translatedText' => 'Bonjour']]]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'fr', 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'en', $body['source'] );
            $this->assertEquals( 'fr', $body['target'] );
        } );

        $this->assertEquals( ['Bonjour'], $response->texts() );
    }


    public function testTranslateWithOptions() : void
    {
        $response = $this->prisma( 'text', 'google', ['api_key' => 'test'] )
            ->response( ['data' => ['translations' => [['translatedText' => 'Hallo']]]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de', null, null, ['model' => 'nmt', 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'nmt', $body['model'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( ['Hallo'], $response->texts() );
    }


    public function testTranslateWithCustomUrl() : void
    {
        $response = $this->prisma( 'text', 'google', ['api_key' => 'test', 'url' => 'https://custom.googleapis.com'] )
            ->response( ['data' => ['translations' => [['translatedText' => 'Hallo']]]] )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://custom.googleapis.com/language/translate/v2?key=test', (string) $request->getUri() );
        } );
    }


    public function testTranslateError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'google', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Forbidden']], status: 403, reason: 'Forbidden' )
            ->ensure( 'translate' )
            ->translate( ['Hello'], 'de' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'google', [] );
    }
}
