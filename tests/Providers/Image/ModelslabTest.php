<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ModelslabTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $response = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/a.png']] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['width' => 512, 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://modelslab.com/api/v6/images/text2img', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'test', $body['key'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 512, $body['width'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( 'https://cdn.modelslab.com/a.png', $response->first()?->url() );
    }


    public function testImagineError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'error', 'message' => 'Invalid key'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );
    }


    public function testImagineProcessingPolls() : void
    {
        $provider = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'processing', 'fetch_result' => 'https://modelslab.com/api/v6/images/fetch/1', 'eta' => 1] );
        $this->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/b.png']] );

        $response = $provider->ensure( 'imagine' )->imagine( 'a cat' );

        // the queued job is resolved by polling the fetch_result URL
        $this->assertTrue( $response->ready() );
        $this->assertEquals( 'https://cdn.modelslab.com/b.png', $response->first()?->url() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'modelslab', [] );
    }
}
