<?php

namespace Tests\Files;

use Aimeos\Prisma\Exceptions\ForbiddenException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Http\Network;
use Aimeos\Prisma\Http\Resolver;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\Http\CurlMock;
use Psr\Http\Message\RequestInterface;


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


    #[TestWith( ['10.0.0.5'] )]
    #[TestWith( ['100.100.100.200'] )]
    #[TestWith( ['100.64.0.1'] )]
    #[TestWith( ['198.18.0.1'] )]
    public function testBinaryRejectsNonPublicIpInStrictMode( string $ip ) : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'does not resolve to a public address' );

        File::fromUrl( 'http://' . $ip . '/internal.png', strict: true )
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


    public function testBytes() : void
    {
        $this->assertSame( 5, File::fromBinary( 'hello' )->bytes() );
        $this->assertSame( 5, File::fromBase64( base64_encode( 'hello' ) )->bytes() );
        $this->assertSame( 6, File::fromBase64( base64_encode( 'hello!' ) )->bytes() );
        $this->assertSame( 100, File::fromBase64( chunk_split( base64_encode( str_repeat( 'x', 100 ) ), 76 ) )->bytes() );
        $this->assertNull( File::fromUrl( 'https://example.com/file.bin' )->bytes() );

        $stream = Utils::tryFopen( 'php://temp', 'w+' );
        fwrite( $stream, 'hello world' );
        rewind( $stream );
        fseek( $stream, 6 );

        // only the unread content is counted
        $this->assertSame( 5, File::fromStream( $stream )->bytes() );
    }


    public function testChunks() : void
    {
        $this->assertSame( ['he', 'll', 'o'], iterator_to_array( File::fromBinary( 'hello' )->chunks( 2 ) ) );
        $this->assertSame( [], iterator_to_array( File::fromBinary( '' )->chunks( 2 ) ) );

        $stream = Utils::tryFopen( 'php://temp', 'w+' );
        fwrite( $stream, 'hello' );
        rewind( $stream );

        $file = File::fromStream( $stream );

        // seekable streams are rewound, so the content can be read again
        $this->assertSame( ['hel', 'lo'], iterator_to_array( $file->chunks( 3 ) ) );
        $this->assertSame( 5, $file->bytes() );
        $this->assertSame( 'hello', $file->binary() );
    }


    public function testDataUri() : void
    {
        $this->assertSame( 'data:text/plain;base64,aGVsbG8=', File::fromBinary( 'hello', 'text/plain' )->dataUri() );
    }


    public function testUri() : void
    {
        $this->assertSame( 'https://example.com/a.txt', File::fromUrl( 'https://example.com/a.txt' )->uri() );
        $this->assertSame( 'data:text/plain;base64,aGVsbG8=', File::fromBinary( 'hello', 'text/plain' )->uri() );
    }


    public function testBinaryPinsResolvedIp() : void
    {
        $mock = new MockHandler( [new Response( 200, [], 'hello' )] );

        FileTestProxy::fromUrl( 'http://files.example.com:8080/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( HandlerStack::create( new CurlMock( $mock ) ) )
            ->binary();

        // the pin is the innermost middleware, so only the handler sees its options
        $options = (array) $mock->getLastOptions();

        $this->assertFalse( $options['allow_redirects'] );
        $this->assertEquals( ['files.example.com:8080:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE] );
        // slowly trickling downloads never stall, so the transfer time is bounded too
        $this->assertSame( 600, $options['timeout'] );
    }


    public function testBinaryRejectsProxyInStrictMode() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'through a proxy' );

        $handler = $this->handler( new Response( 200, [], 'hello' ) );
        $handler->push( fn( callable $next ) => fn( $request, array $options ) => $next( $request, ['proxy' => 'http://proxy.test:3128'] + $options ) );

        FileTestProxy::fromUrl( 'http://files.example.com/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $handler )
            ->binary();
    }


    public function testNonStrictFetchIsNotPinned() : void
    {
        $mock = new MockHandler( [new Response( 200, [], 'hello' )] );

        File::fromUrl( 'http://files.example.com/file.png', strict: false )
            ->withClientHandler( HandlerStack::create( $mock ) )
            ->binary();

        $options = (array) $mock->getLastOptions();

        $this->assertFalse( $options['allow_redirects'] );
        $this->assertArrayNotHasKey( CURLOPT_RESOLVE, $options['curl'] );
    }


    public function testMimeTypeStream() : void
    {
        $stream = Utils::tryFopen( 'php://temp', 'w+' );
        fwrite( $stream, '%PDF-1.4' . str_repeat( ' ', 100000 ) );
        rewind( $stream );

        $file = File::fromStream( $stream );

        // seekable streams are detected by their beginning and aren't read into memory
        $this->assertSame( 'application/pdf', $file->mimeType() );
        $this->assertSame( 0, ftell( $stream ) );
        $this->assertSame( 100008, $file->bytes() );
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
        $this->assertArrayNotHasKey( CURLOPT_RESOLVE, $history[0]['options']['curl'] );
    }


    public function testNonStrictFetchEnforcesLimit() : void
    {
        $reads = 0;
        $body = FnStream::decorate( Utils::streamFor( 'hello' ), [
            '__toString' => function() use ( &$reads ) : string {
                $reads++;
                return 'hello';
            },
        ] );
        $file = FileTestProxy::fromUrl( 'http://example.com/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $this->handler( new Response( 200, ['Content-Length' => '5'], $body ) ) );

        try {
            $file->withInternalHosts()->fetchUrl( 'http://example.com/file.png', 4 );
            $this->fail( 'Expected the body size limit to be enforced' );
        } catch( PrismaException $e ) {
            $this->assertMatchesRegularExpression( '/exceeds the maximum size/', $e->getMessage() );
            $this->assertSame( 0, $reads );
        }
    }


    public function testLimitAbortsTransfer() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/exceeds the maximum size/' );

        File::fromUrl( 'http://8.8.8.8/big' )
            ->withClientHandler( $this->curl( new Response( 200, [], 'hello world' ) ) )
            ->maxSize( 4 )
            ->stream();
    }


    public function testSampleAbortsTransfer() : void
    {
        $file = FileTestProxy::fromUrl( 'http://8.8.8.8/file.png' )->withClientHandler( $this->curl(
            new Response( 302, ['Location' => 'http://8.8.8.8/final.png'], 'redirecting' ),
            new Response( 200, [], 'hello world' )
        ) );

        $this->assertSame( 'hell', $file->withInternalHosts()->fetchUrl( 'http://8.8.8.8/file.png', -4 ) );
    }


    public function testNegativeLimitReturnsBoundedSample() : void
    {
        $file = FileTestProxy::fromUrl( 'http://example.com/file.png' )
            ->resolveTo( '8.8.8.8' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );

        $this->assertSame( 'hell', $file->withInternalHosts()->fetchUrl( 'http://example.com/file.png', -4 ) );
    }


    public function testWithInternalHosts() : void
    {
        $file = File::fromUrl( 'http://10.0.0.5/internal.png' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'internal' ) ) );

        $this->assertEquals( 'internal', $file->withInternalHosts()->binary() );
    }


    public function testBinaryRejectsNonHttpScheme() : void
    {
        $this->expectException( PrismaException::class );

        File::fromUrl( 'file:///etc/passwd' )->binary();
    }


    public function testNonStrictFetchRejectsNonHttpScheme() : void
    {
        $this->expectException( PrismaException::class );

        // curl is limited to http(s) by Guzzle
        File::fromUrl( 'file:///etc/passwd', strict: false )->binary();
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
        $this->assertSame( 'http://10.0.0.6/final.png', (string) $history[1]['request']->getUri() );
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
            $this->assertStringContainsString( 'does not resolve to a public address', $e->getMessage() );
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


    public function testSerializeReadsStream() : void
    {
        $stream = fopen( 'php://memory', 'r+' );
        $this->assertIsResource( $stream );

        fwrite( $stream, 'hello world' );
        rewind( $stream );

        // stream resources cannot be serialized, so the content must travel as binary
        $file = unserialize( serialize( Audio::fromStream( $stream, 'audio/wav' ) ) );
        fclose( $stream );

        $this->assertInstanceOf( Audio::class, $file );
        $this->assertSame( 'hello world', $file->binary() );
        $this->assertSame( 'audio/wav', $file->mimeType() );
    }


    public function testSerializeWithClientHandler() : void
    {
        $file = File::fromUrl( 'http://10.0.0.5/file.png', strict: false )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );

        // handler stacks contain closures, so only the host restriction is kept
        $copy = unserialize( serialize( $file ) );

        $this->assertInstanceOf( File::class, $copy );
        $copy->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );
        $this->assertSame( 'hello', $copy->binary() );
    }


    public function testSerializeSubclassKeepsInternalHosts() : void
    {
        // the network is a private property of File, so it must be restored for subclasses too
        $copy = unserialize( serialize( Audio::fromUrl( 'http://10.0.0.5/file.wav', strict: false ) ) );

        $this->assertInstanceOf( Audio::class, $copy );
        $copy->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );
        $this->assertSame( 'hello', $copy->binary() );
    }


    #[TestWith( ["\0*\0strict"] )]
    #[TestWith( ['strict'] )]
    public function testUnserializeStrictOfOlderVersions( string $key ) : void
    {
        $file = File::fromUrl( 'http://10.0.0.5/file.png' );
        $file->__unserialize( ['url' => 'http://10.0.0.5/file.png', $key => false, 'fetchHandler' => null] );

        $file->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );
        $this->assertSame( 'hello', $file->binary() );
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


    public function testMaxSizeUnlimited() : void
    {
        $stream = File::fromUrl( 'http://8.8.8.8/big' )
            ->withClientHandler( $this->curl( new Response( 200, ['Content-Length' => '11'], 'hello world' ) ) )
            ->maxSize( 0 )
            ->maxSize( -1 )
            ->stream();

        $this->assertSame( 'hello world', stream_get_contents( $stream ) );
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


    public function testStreamDownloadsUrl() : void
    {
        $content = str_repeat( 'video', 30000 );
        $file = File::fromUrl( 'http://8.8.8.8/video.mp4' )->withClientHandler( $this->handler(
            new Response( 200, [], $content ),
            new Response( 200, [], $content )
        ) );

        foreach( [$file->stream(), $file->stream()] as $stream )
        {
            $this->assertSame( 'php://temp', stream_get_meta_data( $stream )['uri'] );
            $this->assertSame( $content, stream_get_contents( $stream ) );
            fclose( $stream );
        }
    }


    public function testStreamEnforcesMaxSize() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/exceeds the maximum size/' );

        File::fromUrl( 'http://8.8.8.8/big' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello world' ) ) )
            ->maxSize( 4 )
            ->stream();
    }


    /** @param class-string<\Throwable> $exception */
    #[TestWith( [403, ForbiddenException::class] )]
    #[TestWith( [404, NotFoundException::class] )]
    #[TestWith( [410, NotFoundException::class] )]
    #[TestWith( [500, PrismaException::class] )]
    public function testStreamFailedUrl( int $status, string $exception ) : void
    {
        $file = File::fromUrl( 'http://8.8.8.8/video.mp4' )
            ->withClientHandler( $this->handler( new Response( $status ) ) );

        try {
            $file->stream();
            $this->fail( 'Expected exception not thrown' );
        } catch( PrismaException $e ) {
            $this->assertSame( $exception, get_class( $e ) );
        }
    }


    public function testStreamRejectsEmptyUrl() : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessageMatches( '/or it is empty/' );

        File::fromUrl( 'http://8.8.8.8/empty' )
            ->withClientHandler( $this->handler( new Response( 200, [], '' ) ) )
            ->stream();
    }


    public function testStreamReusesFetchedUrl() : void
    {
        $file = File::fromUrl( 'http://8.8.8.8/image.png' )
            ->withClientHandler( $this->handler( new Response( 200, [], 'hello' ) ) );

        $this->assertSame( 'hello', $file->binary() );
        $this->assertSame( 'hello', stream_get_contents( $file->stream() ) );
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


    /**
     * Emulates curl, which aborts the transfer with an error if the sink doesn't accept all data.
     */
    private function curl( Response ...$responses ) : HandlerStack
    {
        return HandlerStack::create( function( RequestInterface $request, array $options ) use ( &$responses ) {
            $response = array_shift( $responses );
            $this->assertInstanceOf( Response::class, $response );

            $options['on_headers']( $response );
            $content = (string) $response->getBody();

            if( $options['sink']->write( $content ) < strlen( $content ) ) {
                return Create::rejectionFor( new RequestException( 'cURL error 23', $request ) );
            }

            return Create::promiseFor( $response );
        } );
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

    public function fetchUrl( string $url, int $limit ) : string
    {
        return (string) $this->fetchBody( $url, $limit );
    }


    public function resolveTo( string $ip ) : static
    {
        return $this->withNetwork( new Network( new FileTestResolver( $ip ) ) );
    }
}


class FileTestResolver extends Resolver
{
    public function __construct( private string $ip ) {}


    public function resolve( string $host ) : array
    {
        return [$this->ip];
    }
}
