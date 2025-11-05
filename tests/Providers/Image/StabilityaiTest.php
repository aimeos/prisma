<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class StabilityaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testErase()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->erase(
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                ImageFile::fromBinary( 'PNG', 'image/png' )
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/edit/erase', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testImage()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->image( 'prompt', [ImageFile::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/generate/ultra', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testInpaint()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->inpaint(
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/edit/inpaint', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testIsolate()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->isolate( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/edit/remove-background', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testUncrop()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->uncrop( ImageFile::fromBinary( 'PNG', 'image/png' ), 100, 0, 0, 0 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/edit/outpaint', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testUpscale()
    {
        $file = $this->prisma( 'image', 'stabilityai', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->upscale( ImageFile::fromBinary( 'PNG', 'image/png' ), 1000, 1000 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.stability.ai/v2beta/stable-image/upscale/conservative', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
