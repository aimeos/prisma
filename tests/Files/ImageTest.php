<?php

namespace Tests\Files;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;


class ImageTest extends TestCase
{
    // 1x1 transparent PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';


    public function testSize() : void
    {
        $this->assertSame( [1, 1], Image::fromBase64( self::PNG, 'image/png' )->size() );
    }


    public function testSizeUrl() : void
    {
        $mock = new MockHandler( [new Response( 200, [], (string) base64_decode( self::PNG ) )] );
        $image = Image::fromUrl( 'http://8.8.8.8/a.png', 'image/png' )->withClientHandler( HandlerStack::create( $mock ) );

        $this->assertSame( [1, 1], $image->size() );
        $this->assertSame( 0, $mock->count() );
    }


    public function testSizeUrlLargeHeader() : void
    {
        // the size isn't part of the fetched header, so the image is downloaded completely
        $mock = new MockHandler( [new Response( 200, [], str_repeat( "\0", 70000 ) ), new Response( 200, [], (string) base64_decode( self::PNG ) )] );
        $image = Image::fromUrl( 'http://8.8.8.8/a.png', 'image/png' )->withClientHandler( HandlerStack::create( $mock ) );

        $this->assertSame( [1, 1], $image->size() );
        $this->assertSame( 0, $mock->count() );
    }


    public function testSizeAndMimeTypeFetchHeaderOnce() : void
    {
        $png = (string) base64_decode( self::PNG );
        $mock = new MockHandler( [new Response( 200, [], $png . str_repeat( "\0", 70000 ) )] );
        $image = Image::fromUrl( 'http://8.8.8.8/a.png' )->withClientHandler( HandlerStack::create( $mock ) );

        $this->assertSame( 'image/png', $image->mimeType() );
        $this->assertSame( [1, 1], $image->size() );
        $this->assertSame( 0, $mock->count() );
        $this->assertSame( 'bytes=0-65535', $mock->getLastRequest()?->getHeaderLine( 'Range' ) );
    }


    public function testSmallUrlFilesAreFetchedOnce() : void
    {
        $png = (string) base64_decode( self::PNG );
        $mock = new MockHandler( [new Response( 200, [], $png )] );
        $image = Image::fromUrl( 'http://8.8.8.8/a.png' )->withClientHandler( HandlerStack::create( $mock ) );

        $this->assertSame( 'image/png', $image->mimeType() );
        $this->assertSame( $png, $image->binary() );
        $this->assertSame( 0, $mock->count() );
    }


    public function testDoesNotSerializeHeader() : void
    {
        $mock = new MockHandler( [new Response( 200, [], str_repeat( "\0", 70000 ) )] );
        $image = Image::fromUrl( 'http://8.8.8.8/a.png', 'image/png' )->withClientHandler( HandlerStack::create( $mock ) );

        try {
            $image->size();
        } catch( \Throwable ) {}

        $this->assertStringNotContainsString( 'prefix', serialize( $image ) );
    }


    public function testSizeInvalid() : void
    {
        $this->expectException( BadRequestException::class );

        Image::fromBinary( 'no image', 'image/png' )->size();
    }
}
