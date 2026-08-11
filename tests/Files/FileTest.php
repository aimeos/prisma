<?php

namespace Tests\Files;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\File;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;


class FileTest extends TestCase
{
    private string $path = '';


    public function testBinaryAllowsPrivateIp() : void
    {
        $content = File::fromUrl( 'http://10.0.0.5/internal.png', strict: false )
            ->withClientHandler( $this->handler( new Response( 200, [], 'internal' ) ) )
            ->binary();

        $this->assertEquals( 'internal', $content );
    }


    public function testBinaryRejectsPrivateIpInStrictMode() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'does not resolve to an allowed address' );

        File::fromUrl( 'http://10.0.0.5/internal.png', strict: true )
            ->withClientHandler( $this->handler( new Response( 200, [], 'internal' ) ) )
            ->binary();
    }


    public function testBinaryFetchesHost() : void
    {
        $content = File::fromUrl( 'http://8.8.8.8/file.png' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) )
            ->binary();

        $this->assertEquals( 'hello', $content );
    }


    public function testBinaryPinsResolvedIp() : void
    {
        $history = [];
        $handler = $this->handler( new Response( 200, [], 'hello' ) );
        $handler->push( Middleware::history( $history ) );

        FileTestProxy::fromUrl( 'http://files.example.com:8080/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $handler )
            ->binary();

        $this->assertFalse( $history[0]['options']['allow_redirects'] );
        $this->assertEquals( ['files.example.com:8080:8.8.8.8'], $history[0]['options']['curl'][CURLOPT_RESOLVE] );
    }


    public function testNonStrictFetchPinsResolvedIp() : void
    {
        $history = [];
        $handler = $this->handler( new Response( 200, [], 'hello' ) );
        $handler->push( Middleware::history( $history ) );

        FileTestProxy::fromUrl( 'http://files.example.com/file.png', strict: false )
            ->resolveTo( '10.0.0.5' )
            ->withClientHandler( $handler )
            ->binary();

        $this->assertFalse( $history[0]['options']['allow_redirects'] );
        $this->assertEquals( ['files.example.com:80:10.0.0.5'], $history[0]['options']['curl'][CURLOPT_RESOLVE] );
    }


    public function testMimeTypeUsesConfiguredStrictMode() : void
    {
        $history = [];
        $handler = $this->handler( new Response( 200, [], 'hello' ) );
        $handler->push( Middleware::history( $history ) );

        File::fromUrl( 'http://10.0.0.5/file.png', strict: false )
            ->withClientHandler( $handler )
            ->mimeType();

        $this->assertFalse( $history[0]['options']['allow_redirects'] );
        $this->assertEquals( ['10.0.0.5:80:10.0.0.5'], $history[0]['options']['curl'][CURLOPT_RESOLVE] );
    }


    public function testNonStrictFetchEnforcesLimit() : void
    {
        $reads = 0;
        $stream = Utils::streamFor( 'hello' );
        $body = FnStream::decorate( $stream, [
            'read' => function( int $length ) use ( &$reads, $stream ) : string {
                $reads++;
                return $stream->read( $length );
            },
            'getContents' => function() use ( &$reads, $stream ) : string {
                $reads++;
                return $stream->getContents();
            },
        ] );
        $file = FileTestProxy::fromUrl( 'http://example.com/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $this->handler( new Response( 200, [], $body ) ) );

        try {
            $file->fetchUrl( 'http://example.com/file.png', 4, false );
            $this->fail( 'Expected the body size limit to be enforced' );
        } catch( PrismaException $e ) {
            $this->assertMatchesRegularExpression( '/exceeds the maximum size/', $e->getMessage() );
            $this->assertSame( 0, $reads );
        }
    }


    public function testNegativeLimitReturnsBoundedSample() : void
    {
        $file = FileTestProxy::fromUrl( 'http://example.com/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );

        $this->assertSame( 'hell', $file->fetchUrl( 'http://example.com/file.png', -4, false ) );
    }


    public function testRestrictedHostsAllowHttpsSubdomain() : void
    {
        $file = FileTestProxy::fromUrl( 'https://cdn.replicate.delivery/image.png' )
            ->restrictHosts( ['replicate.delivery'] );

        $this->assertTrue( $file->allowsUrl( 'https://cdn.replicate.delivery/image.png' ) );
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
        $history = [];
        $handler = $this->handler(
            new Response( 302, ['Location' => 'http://10.0.0.6/final.png'] ),
            new Response( 200, [], 'final' )
        );
        $handler->push( Middleware::history( $history ) );

        $content = File::fromUrl( 'http://10.0.0.5/redirect', strict: false )
            ->withClientHandler( $handler )
            ->binary();

        $this->assertEquals( 'final', $content );
        $this->assertEquals( ['10.0.0.5:80:10.0.0.5'], $history[0]['options']['curl'][CURLOPT_RESOLVE] );
        $this->assertEquals( ['10.0.0.6:80:10.0.0.6'], $history[1]['options']['curl'][CURLOPT_RESOLVE] );
    }


    public function testStrictRedirectRejectsPrivateIp() : void
    {
        $history = [];
        $handler = $this->handler(
            new Response( 302, ['Location' => 'http://10.0.0.6/final.png'] ),
            new Response( 200, [], 'final' )
        );
        $handler->push( Middleware::history( $history ) );

        try
        {
            File::fromUrl( 'http://8.8.8.8/redirect', strict: true )
                ->withClientHandler( $handler )
                ->binary();

            $this->fail( 'Expected the private redirect target to be rejected' );
        }
        catch( PrismaException $e )
        {
            $this->assertStringContainsString( 'does not resolve to an allowed address', $e->getMessage() );
            $this->assertCount( 1, $history );
        }
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

        File::fromUrl( 'http://8.8.8.8/big' )
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


class FileTestProxy extends File
{
    private ?string $resolvedIp = null;


    public function allowsUrl( string $url ) : bool
    {
        return $this->validUrl( $url );
    }


    public function fetchUrl( string $url, int $limit, bool $strict ) : string
    {
        return $this->fetch( $url, $limit, $strict );
    }


    public function resolveTo( string $ip ) : self
    {
        $this->resolvedIp = $ip;
        return $this;
    }


    protected function resolve( string $host, bool $strict ) : ?string
    {
        return $this->resolvedIp ?? parent::resolve( $host, $strict );
    }
}
