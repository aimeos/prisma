<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ClipdropTest extends TestCase
{
    use MakesPrismaRequests;


    public function testBackground()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->background( ImageFile::fromBinary( 'PNG', 'image/png' ), 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-api-key' ) );
            $this->assertEquals( 'https://clipdrop-api.co/replace-background/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testClear()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->clear( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/remove-background/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testDetext()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->detext( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/remove-text/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testErase()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->erase( ImageFile::fromBinary( 'PNG', 'image/png' ), ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/cleanup/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testImage()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->image( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/text-to-image/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testStudio()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->studio( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/product-photography/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testUncrop()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->uncrop( ImageFile::fromBinary( 'PNG', 'image/png' ), 100, 0, 0, 0 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/uncrop/v1', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }


    public function testUpscale()
    {
        $file = $this->prisma( 'image', 'clipdrop', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'x-credits-consumed' => 1, 'x-remaining-credits' => 99] )
            ->upscale( ImageFile::fromBinary( 'PNG', 'image/png' ), 2000, 2000 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://clipdrop-api.co/image-upscaling/v1/upscale', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1, 'x-remaining-credits' => 99], $file->usage() );
    }
}
