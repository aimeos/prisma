<?php

namespace Tests\Responses;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Http\Network;
use Aimeos\Prisma\Responses\FileResponse;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Tests\Http\CurlMock;
use Tests\Http\FakeResolver;


class FileResponseTest extends TestCase
{
    /** @var array<int, RequestInterface> */
    private array $sent = [];


    public function testAddUrl() : void
    {
        $response = FileResponse::fromFiles( [] )->withNetwork( $this->network( 'image' ) )
            ->addUrl( 'https://api.example.com/image.png', Image::class, 'image/png', 'out' );

        $file = $response->files()['out'] ?? null;

        $this->assertInstanceOf( Image::class, $file );
        $this->assertSame( 'image/png', $file->mimeType() );
        $this->assertSame( 'image', $file->binary() );
    }


    public function testAddUrlMaxSize() : void
    {
        $response = FileResponse::fromFiles( [] )->withNetwork( $this->network( 'hello world' ) )->withMaxSize( 4 )
            ->addUrl( 'https://api.example.com/image.png' );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/exceeds the maximum size/' );

        $response->first()?->binary();
    }


    public function testAddUrlMaxSizeAfter() : void
    {
        $response = FileResponse::fromFiles( [] )->withNetwork( $this->network( 'hello world' ) )
            ->addUrl( 'https://api.example.com/image.png' )->withMaxSize( 4 );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/exceeds the maximum size/' );

        $response->first()?->binary();
    }


    public function testAddUrlWithoutMiddleware() : void
    {
        FileResponse::fromFiles( [] )->withNetwork( $this->network( 'image' ) )
            ->addUrl( 'https://api.example.com/image.png' )->first()?->binary();

        // middleware of the provider stack may add credentials
        $this->assertSame( [], $this->sent[0]->getHeader( 'X-Token' ) );
    }


    public function testAddUrlWithoutNetwork() : void
    {
        $this->expectException( PrismaException::class );
        FileResponse::fromFiles( [] )->addUrl( 'https://api.example.com/image.png' );
    }


    public function testFromStream() : void
    {
        $stream = tmpfile();
        $this->assertIsResource( $stream );

        try {
            $this->assertSame( $stream, FileResponse::fromStream( $stream )->stream() );
        } finally {
            fclose( $stream );
        }
    }


    public function testJsonSerializeExposesFileMetadataOnly() : void
    {
        $data = FileResponse::fromBinary( 'binarydata', 'image/png' )
            ->as( 'cat.png' )
            ->withDescription( 'a cat' )
            ->jsonSerialize();

        $this->assertEquals( 'a cat', $data['description'] );
        $this->assertCount( 1, $data['files'] );
        $this->assertEquals( 'cat.png', $data['files'][0]['filename'] );
        $this->assertEquals( 'image/png', $data['files'][0]['mimeType'] );

        // binary content is never part of the serialized form
        $this->assertArrayNotHasKey( 'binary', $data['files'][0] );
        $this->assertArrayNotHasKey( 'base64', $data['files'][0] );
    }


    private function network( string $body ) : Network
    {
        $stack = HandlerStack::create( new CurlMock( function( RequestInterface $request, array $options ) use ( $body ) {
            $this->sent[] = $request;

            // file downloads pass a sink the body must be written to
            if( ( $options['sink'] ?? null ) instanceof StreamInterface ) {
                $options['sink']->write( $body );
            }

            return Create::promiseFor( new Response( 200, [], $body ) );
        } ) );
        $stack->push( Middleware::mapRequest( fn( RequestInterface $request ) => $request->withHeader( 'X-Token', 'secret' ) ) );

        return ( new Network( new FakeResolver() ) )->withHandler( $stack );
    }
}
