<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ZTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $response = $this->prisma( 'image', 'z', ['api_key' => 'test'] )
            ->response( [
                'data' => [['url' => 'https://example.com/fox.png']]
            ] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['quality' => 'standard', 'size' => '1024x1024', 'user_id' => 'user-123', 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertStringContainsString( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://api.z.ai/api/paas/v4/images/generations', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'glm-image', $body['model'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 'standard', $body['quality'] );
            $this->assertEquals( '1024x1024', $body['size'] );
            $this->assertEquals( 'user-123', $body['user_id'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( 'https://example.com/fox.png', $response->first()?->url() );
    }


    public function testImagineFromBase64() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';

        $response = $this->prisma( 'image', 'z', ['api_key' => 'test'] )
            ->response( [
                'data' => [['b64_json' => $base64]],
            ] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['size' => '1280x1280'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( '1280x1280', $body['size'] );
        } );

        $this->assertEquals( $base64, $response->base64() );
        $this->assertEquals( 'image/png', $response->mimeType() );
    }


    public function testImagineError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'z', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'imagine' )
            ->imagine( 'a fox' );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'z', [] );
    }
}

