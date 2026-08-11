<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ReplicateTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'succeeded', 'output' => ['https://replicate.delivery/a.png']] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['aspect_ratio' => '1:1', 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions',
                (string) $request->getUri()
            );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'wait', $request->getHeaderLine( 'Prefer' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'a fox', $body['input']['prompt'] );
            $this->assertEquals( '1:1', $body['input']['aspect_ratio'] );
            $this->assertArrayNotHasKey( 'unknown', $body['input'] );
        } );

        $this->assertEquals( 'https://replicate.delivery/a.png', $response->first()?->url() );
    }


    public function testImagineFailed() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'failed', 'error' => 'boom'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );
    }


    public function testImaginePolls() : void
    {
        $provider = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'processing', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']] );
        $this->response( ['status' => 'succeeded', 'output' => ['https://replicate.delivery/b.png']] );

        $response = $provider->ensure( 'imagine' )->imagine( 'a cat' );

        // an unfinished prediction is resolved by polling its status URL
        $this->assertTrue( $response->ready() );
        $this->assertEquals( 'https://replicate.delivery/b.png', $response->first()?->url() );
    }


    public function testImagineStringOutput() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://replicate.delivery/single.png'] )
            ->model( 'owner/model' )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://api.replicate.com/v1/models/owner/model/predictions',
                (string) $request->getUri()
            );
        } );

        $this->assertEquals( 'https://replicate.delivery/single.png', $response->first()?->url() );
    }


    public function testGeneratedImageRejectsNonHttpsDownload() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'http://replicate.delivery/image.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->assertEquals( 'http://replicate.delivery/image.png', $response->first()?->url() );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Invalid or unsafe URL' );

        $response->first()?->binary();
    }


    public function testGeneratedImageRejectsHostOutsideAllowlist() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://169.254.169.254/latest/meta-data'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Invalid or unsafe URL' );

        $response->first()?->binary();
    }


    public function testGeneratedImageAllowsHttpsSubdomain() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://cdn.replicate.delivery/image.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $image = $response->first();
        $image?->withClientHandler( HandlerStack::create( new MockHandler( [new Response( 200, [], 'image' )] ) ) );

        $this->assertEquals( 'image', $image?->binary() );
    }


    public function testGeneratedImageAllowsConfiguredHost() : void
    {
        $response = $this->prisma( 'image', 'replicate', [
            'api_key' => 'test',
            'download_hosts' => ['images.example.com'],
        ] )
            ->response( ['status' => 'succeeded', 'output' => 'https://images.example.com/image.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $image = $response->first();
        $image?->withClientHandler( HandlerStack::create( new MockHandler( [new Response( 200, [], 'image' )] ) ) );

        $this->assertEquals( 'image', $image?->binary() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'replicate', [] );
    }
}
