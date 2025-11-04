<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class RemovebgTest extends TestCase
{
    use MakesPrismaRequests;


    public function testClear()
    {
        $file = $this->prisma( 'image', 'removebg', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png', 'X-Credits-Charged' => '1', 'X-Width' => '100', 'X-Height' => '100'] )
            ->clear( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-api-key' ) );
            $this->assertEquals( 'https://api.remove.bg/v1.0/removebg', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['used' => 1], $file->usage() );
        $this->assertEquals( ['X-Credits-Charged' => '1', 'X-Width' => '100', 'X-Height' => '100'], $file->meta() );
    }


    public function testRelocate()
    {
        $file = $this->prisma( 'image', 'removebg', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->relocate( ImageFile::fromBinary( 'PNG', 'image/png' ), ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.remove.bg/v1.0/removebg', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testStudio()
    {
        $file = $this->prisma( 'image', 'removebg', ['api_key' => 'test'] )
            ->response( 'PNG', ['Content-Type' => 'image/png'] )
            ->studio( ImageFile::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.remove.bg/v1.0/removebg', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
