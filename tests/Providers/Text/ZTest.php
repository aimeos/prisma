<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ZTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'z', [] );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = $this->prisma( 'text', 'z', ['api_key' => 'test'] )
            ->streamResponse( [
                ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]],
                ['data' => ['choices' => [['delta' => ['content' => ' world']]]]],
                ['data' => ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 10]]],
                ['data' => '[DONE]'],
            ] )
            ->ensure( 'stream' )
            ->stream( 'Say hello' );

        foreach( $response->stream() as $chunk ) {
            $deltas[] = $chunk;
        }

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.z.ai/api/paas/v4/chat/completions', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'glm-5.2', $body['model'] );
            $this->assertTrue( $body['stream'] );
        } );

        $this->assertSame( ['Hello', ' world'], $deltas );
        $this->assertEquals( 'Hello world', $response->text() );
    }


    public function testWrite() : void
    {
        $response = $this->prisma( 'text', 'z', ['api_key' => 'test'] )
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
            $this->assertEquals( 'https://api.z.ai/api/paas/v4/chat/completions', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'glm-5.2', $body['model'] );
            $this->assertEquals( 'Say hello', $body['messages'][0]['content'][0]['text'] );
            $this->assertCount( 1, $body['messages'] );
        } );

        $this->assertEquals( 'Hello world', $response->text() );
        $this->assertEquals( ['Hello world'], $response->texts() );
        $this->assertEquals( 10, $response->usage()['used'] );
    }
}
