<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class XaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';

        $response = $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => [['b64_json' => $base64, 'revised_prompt' => 'a red fox']],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['n' => 2, 'response_format' => 'b64_json', 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.x.ai/v1/images/generations', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'grok-2-image', $body['model'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 2, $body['n'] );
            $this->assertEquals( 'b64_json', $body['response_format'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertCount( 1, $response->files() );
        $this->assertEquals( $base64, $response->base64() );
    }


    public function testImagineError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );
    }


    public function testImagineFromUrl() : void
    {
        $response = $this->prisma( 'image', 'xai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => [['url' => 'https://example.com/fox.png']],
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );

        $this->assertEquals( 'https://example.com/fox.png', $response->first()?->url() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'xai', [] );
    }
}
