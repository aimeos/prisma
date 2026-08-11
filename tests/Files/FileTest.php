<?php

namespace Tests\Files;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\File;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;


class FileTest extends TestCase
{
    private string $path = '';


    public function testBinaryAllowsPrivateIp() : void
    {
        // private and reserved addresses are intentionally permitted
        $content = File::fromUrl( 'http://10.0.0.5/internal.png' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'internal' ) ) )
            ->binary();

        $this->assertEquals( 'internal', $content );
    }


    public function testBinaryFetchesHost() : void
    {
        $content = File::fromUrl( 'http://example.com/file.png' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) )
            ->binary();

        $this->assertEquals( 'hello', $content );
    }


    public function testBinaryRejectsNonHttpScheme() : void
    {
        $this->expectException( PrismaException::class );

        File::fromUrl( 'file:///etc/passwd' )->binary();
    }


    public function testBinaryRejectsPathTraversal() : void
    {
        $this->expectException( PrismaException::class );

        File::fromUrl( 'http://example.com/../etc/passwd' )->binary();
    }


    public function testFollowsRedirect() : void
    {
        $content = File::fromUrl( 'http://example.com/redirect' )
            ->withClientHandler( $this->handler(
                new Response( 302, ['Location' => 'http://example.com/final.png'] ),
                new Response( 200, [], 'final' )
            ) )
            ->binary();

        $this->assertEquals( 'final', $content );
    }


    public function testFromLocalPathReadsLocalFile() : void
    {
        $file = File::fromLocalPath( $this->tempFile( 'hello world' ) );

        $this->assertEquals( 'hello world', $file->binary() );
    }


    public function testFromLocalPathRejectsRemoteScheme() : void
    {
        $this->expectException( PrismaException::class );

        File::fromLocalPath( 'http://169.254.169.254/latest/meta-data/' );
    }


    public function testFromLocalPathRejectsWrapper() : void
    {
        $this->expectException( PrismaException::class );

        File::fromLocalPath( 'php://filter/read=convert.base64-encode/resource=/etc/passwd' );
    }


    public function testFromStreamSupportsNonSeekableResources() : void
    {
        $streams = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
        $this->assertIsArray( $streams );

        [$stream, $writer] = $streams;
        fwrite( $writer, 'hello world' );
        fclose( $writer );

        $file = File::fromStream( $stream, 'text/plain' );

        try
        {
            $this->assertSame( $stream, $file->stream() );
            $this->assertSame( 'hello ', fread( $stream, 6 ) );
            $this->assertSame( 'world', $file->binary() );
            $this->assertSame( 'text/plain', $file->mimeType() );

            $converted = $file->stream();
            $this->assertNotSame( $stream, $converted );
            $this->assertSame( 'world', stream_get_contents( $converted ) );
            fclose( $converted );
        }
        finally
        {
            fclose( $stream );
        }
    }


    public function testFromStreamRejectsInvalidResource() : void
    {
        $this->expectException( PrismaException::class );

        File::fromStream( 'not a stream' );
    }


    public function testFromStreamRejectsUnreadableStream() : void
    {
        $stream = fopen( $this->tempFile( '' ), 'wb' );
        $this->assertIsResource( $stream );

        try {
            $this->expectException( PrismaException::class );
            File::fromStream( $stream );
        } finally {
            fclose( $stream );
        }
    }


    public function testMaxSizeCapEnforced() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/exceeds the maximum size/' );

        File::fromUrl( 'http://example.com/big' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello world' ) ) )
            ->maxSize( 4 )
            ->binary();
    }


    public function testStreamReturnsReadableStream() : void
    {
        $file = File::fromBinary( 'hello world' );
        $first = $file->stream();

        $this->assertIsResource( $first );
        $this->assertSame( 'hello world', stream_get_contents( $first ) );

        $second = $file->stream();

        $this->assertIsResource( $second );
        $this->assertNotSame( $first, $second );
        $this->assertSame( 'hello world', stream_get_contents( $second ) );

        fclose( $first );
        fclose( $second );
    }


    public function testWavMimeNormalization() : void
    {
        // finfo reports several non-canonical WAV types; they normalize to "audio/wav"
        $this->assertEquals( 'audio/wav', File::fromBinary( 'RIFFdata', 'audio/x-wav' )->mimeType() );
        $this->assertEquals( 'audio/wav', File::fromBase64( base64_encode( 'RIFFdata' ), 'audio/vnd.wave' )->mimeType() );
        $this->assertEquals( 'audio/mpeg', File::fromBinary( 'data', 'audio/mpeg' )->mimeType() );
    }


    public function testAudioAcceptsBrowserContainerMimeTypes() : void
    {
        $this->assertEquals( 'video/mp4', Audio::fromBinary( 'data', 'video/mp4' )->mimeType() );
        $this->assertEquals( 'video/ogg', Audio::fromBinary( 'data', 'video/ogg' )->mimeType() );
        $this->assertEquals( 'video/webm', Audio::fromBinary( 'data', 'video/webm' )->mimeType() );
    }


    protected function tearDown() : void
    {
        if( $this->path !== '' && file_exists( $this->path ) ) {
            unlink( $this->path );
        }
    }


    private function handler( Response ...$responses ) : HandlerStack
    {
        return HandlerStack::create( new MockHandler( $responses ) );
    }


    private function tempFile( string $content ) : string
    {
        $this->path = (string) tempnam( sys_get_temp_dir(), 'prisma' );
        file_put_contents( $this->path, $content );

        return $this->path;
    }
}
