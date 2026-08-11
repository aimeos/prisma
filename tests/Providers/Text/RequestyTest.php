<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class RequestyTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'requesty', [] );
    }


    public function testStream() : void
    {
        $response = $this->prisma( 'text', 'requesty', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]],
                ['data' => ['choices' => [['delta' => ['content' => ' world']]]]],
                ['data' => ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 3]]],
                ['data' => '[DONE]'],
            ] )
            ->stream( 'Say hello' );

        $this->assertEquals( 'Hello world', $response->text() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://router.requesty.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertTrue( $body['stream'] );
        } );
    }


    public function testStructure() : void
    {
        $schema = Schema::for( 'person', ['name' => Schema::string()] );

        $response = $this->prisma( 'text', 'requesty', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"name":"Jane"}']]],
                'usage' => ['total_tokens' => 4],
            ] )
            ->structure( 'Extract', $schema );

        $this->assertEquals( ['name' => 'Jane'], $response->structured() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'json_schema', $body['response_format']['type'] );
            $this->assertEquals( 'person', $body['response_format']['json_schema']['name'] );
        } );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'text', 'requesty', ['api_key' => 'test'] )
            ->response( [
                'data' => [['embedding' => [0.1, 0.2]]],
                'usage' => ['total_tokens' => 2],
            ] )
            ->vectorize( ['hello'], 256 );

        $this->assertEquals( [[0.1, 0.2]], $response->vectors() );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://router.requesty.ai/v1/embeddings', (string) $request->getUri() );
            $this->assertEquals( 'openai/text-embedding-3-small', $body['model'] );
            $this->assertEquals( 256, $body['dimensions'] );
        } );
    }


    public function testWriteWithSiteHeadersAndOptions() : void
    {
        $response = $this->prisma( 'text', 'requesty', [
            'api_key' => 'test',
            'site' => ['http_referer' => 'https://app.example.com', 'x_title' => 'Example'],
        ] )
            ->response( [
                'choices' => [['message' => ['content' => 'Hello world']]],
                'usage' => ['total_tokens' => 10],
            ] )
            ->write( 'Say hello', [], ['temperature' => 0.2, 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'https://router.requesty.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'https://app.example.com', $request->getHeaderLine( 'HTTP-Referer' ) );
            $this->assertEquals( 'Example', $request->getHeaderLine( 'X-Title' ) );
            $this->assertEquals( 'openai/gpt-5.6-luna', $body['model'] );
            $this->assertEquals( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
    }
}
