<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
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


    public function testImagineOutputFormat() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'succeeded', 'output' => ['https://replicate.delivery/a.webp']] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['output_format' => 'webp'] );

        // the requested format is the mime type, so the image isn't fetched to detect it
        $this->assertSame( 'image/webp', $response->first()?->mimeType() );
    }


    public function testImagineFailed() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'failed', 'error' => 'boom'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );
    }


    public function testImagineNoUrl() : void
    {
        $this->expectException( FailedException::class );

        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'starting'] )
            ->imagine( 'a fox' );
    }


    public function testImaginePolls() : void
    {
        $provider = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'processing', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']] );
        $this->response( ['status' => 'succeeded', 'output' => ['https://replicate.delivery/b.png']] );

        $response = $provider->ensure( 'imagine' )->imagine( 'a cat', [], ['output_format' => 'png'] );

        // an unfinished prediction is resolved by polling the URL of the provider
        $this->assertSame( 'https://api.replicate.com/v1/predictions/p1', $response->jobId() );
        $this->assertTrue( $response->ready() );
        $this->assertEquals( 'https://replicate.delivery/b.png', $response->first()?->url() );
        $this->assertSame( 'image/png', $response->first()?->mimeType() );
        $this->assertSame( 'https://api.replicate.com/v1/predictions/p1', (string) $this->requests()[1]->getUri() );
    }


    public function testImaginePollsNoOutput() : void
    {
        $provider = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'processing', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']] );
        $this->response( ['status' => 'succeeded', 'output' => null] );

        $this->expectException( FailedException::class );
        $provider->imagine( 'a cat' )->ready();
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


    public function testGeneratedImageRejectsPrivateIp() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://169.254.169.254/latest/meta-data'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'does not resolve to a public address' );

        // the mocked provider allows internal hosts, which its files inherit
        $response->first()?->withInternalHosts( false )->binary();
    }


    public function testGeneratedImageRejectsResolvablePrivateHost() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://localhost/image.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'does not resolve to a public address' );

        $response->first()?->withInternalHosts( false )->binary();
    }


    public function testGeneratedImageUsesProviderNetwork() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://localhost/image.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        // downloaded by the mocked handler of the provider, which allows internal hosts
        $this->response( 'image' );

        $this->assertEquals( 'image', $response->first()?->binary() );
        $this->assertSame( [], $this->requests()[1]->getHeader( 'Authorization' ) );
    }


    public function testGeneratedImageSizeLimit() : void
    {
        $response = $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => 'https://replicate.delivery/a.png'] )
            ->ensure( 'imagine' )
            ->imagine( 'x' );

        $this->assertSame( 25 * 1024 * 1024, ( new \ReflectionProperty( File::class, 'maxBytes' ) )->getValue( $response->first() ) );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'replicate', [] );
    }
}
