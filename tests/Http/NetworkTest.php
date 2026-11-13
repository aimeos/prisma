<?php

namespace Tests\Http;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Http\Network;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RequiresSetting;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;


class NetworkTest extends TestCase
{
    /** @var array<int, array{RequestInterface, array<string, mixed>}> */
    private array $sent = [];

    /** Number of requests which fail to connect before the others succeed */
    private int $unreachable = 0;


    public function testRejectsUrlsWithoutHost() : void
    {
        $stack = $this->network()->handler();

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Requests to URLs without a host are not allowed' );

        $stack( new Request( 'GET', '/file' ), [] );
    }


    public function testPinsStreamRequests() : void
    {
        $stack = $this->network()->handler();

        $stack( new Request( 'POST', 'https://api.example.com:8443/v1/chat' ), ['stream' => true] )->wait();

        $this->assertSame( 'https://93.184.215.14:8443/v1/chat', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( 'api.example.com:8443', $this->sent[0][0]->getHeaderLine( 'Host' ) );
        $this->assertSame( 'api.example.com', $this->sent[0][1]['stream_context']['ssl']['peer_name'] );
        $this->assertArrayNotHasKey( 'curl', $this->sent[0][1] );
    }


    public function testTriesNextAddressOfStreamRequests() : void
    {
        $this->unreachable = 1;
        $stack = $this->network()->handler();

        $response = $stack( new Request( 'GET', 'https://multi.example.com/v1' ), ['stream' => true] )->wait();

        $this->assertSame( 200, $response->getStatusCode() );
        $this->assertSame( '93.184.215.14', $this->sent[0][0]->getUri()->getHost() );
        $this->assertSame( '[2606:2800:21f:cb07:6820:80da:af6b:8b2c]', $this->sent[1][0]->getUri()->getHost() );
        $this->assertSame( 'multi.example.com', $this->sent[1][0]->getHeaderLine( 'Host' ) );
    }


    public function testSharesTimeoutBetweenAddressesOfStreamRequests() : void
    {
        $this->unreachable = 1;
        $stack = $this->network()->handler();

        $stack( new Request( 'GET', 'https://multi.example.com/v1' ), ['stream' => true, 'timeout' => 10] )->wait();

        $this->assertLessThanOrEqual( 10, $this->sent[0][1]['timeout'] );
        $this->assertLessThanOrEqual( $this->sent[0][1]['timeout'], $this->sent[1][1]['timeout'] );
    }


    public function testLimitsAddressAttemptsOfStreamRequestsByReadTimeout() : void
    {
        $this->unreachable = 1;
        $stack = $this->network()->handler();

        $stack( new Request( 'GET', 'https://multi.example.com/v1' ), ['stream' => true, 'timeout' => 600, 'read_timeout' => 120] )->wait();

        $this->assertEquals( 120, $this->sent[0][1]['timeout'] );
        // the last address has no other one left, so it's only limited by the shared timeout
        $this->assertGreaterThan( 120, $this->sent[1][1]['timeout'] );
        $this->assertSame( 120, $this->sent[1][1]['read_timeout'] );
    }


    public function testDoesNotTryNextAddressAfterTimeout() : void
    {
        $this->unreachable = 2;
        $stack = $this->network()->handler();

        try {
            $stack( new Request( 'GET', 'https://multi.example.com/v1' ), ['stream' => true, 'timeout' => 0.000001] )->wait();
            $this->fail( 'Unreachable hosts must be rejected' );
        } catch( ConnectException $e ) {
            $this->assertCount( 1, $this->sent );
        }
    }


    public function testRejectsStreamRequestsIfNoAddressIsReachable() : void
    {
        $this->unreachable = 2;
        $stack = $this->network()->handler();

        try {
            $stack( new Request( 'GET', 'https://multi.example.com/v1' ), ['stream' => true] )->wait();
            $this->fail( 'Unreachable hosts must be rejected' );
        } catch( ConnectException $e ) {
            $this->assertCount( 2, $this->sent );
        }
    }


    public function testPinsCurlRequests() : void
    {
        $stack = $this->network( true )->handler();

        $stack( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();

        $this->assertSame( 'https://api.example.com/v1', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testPinsStreamRequestsOfCurlStacksByCurl() : void
    {
        $stack = $this->network( true )->handler();

        $stack( new Request( 'GET', 'https://api.example.com/v1' ), ['stream' => true] )->wait();

        $this->assertSame( 'https://api.example.com/v1', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testPinsRequestsOfOtherHandlersByAddress() : void
    {
        $stack = $this->network()->handler();

        $stack( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();

        $this->assertSame( 'https://93.184.215.14/v1', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( 'api.example.com', $this->sent[0][1]['stream_context']['ssl']['peer_name'] );
        $this->assertArrayNotHasKey( 'curl', $this->sent[0][1] );
    }


    #[RequiresSetting( 'allow_url_fopen', '1' )]
    public function testPinsRequestsOfDefaultHandlerByCurlUnlessStreamed() : void
    {
        $handler = fn( RequestInterface $request, array $options ) => Create::promiseFor( new Response( 200 ) )->then( function( $response ) use ( $request, $options ) {
            $this->sent[] = [$request, $options];
            return $response;
        } );

        // Guzzle's default handler sends streamed requests by the StreamHandler and others by cURL
        $network = ( new Network( new FakeResolver() ) )->withHandler( HandlerStack::create( Proxy::wrapStreaming( new CurlMock( $handler ), $handler ) ) );

        $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();
        $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), ['stream' => true] )->wait();

        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
        $this->assertSame( 'https://93.184.215.14/v1', (string) $this->sent[1][0]->getUri() );
    }


    #[RequiresSetting( 'allow_url_fopen', '0' )]
    public function testPinsStreamRequestsOfDefaultHandlerByCurlWithoutUrlFopen() : void
    {
        // without allow_url_fopen, Guzzle's default handler sends streamed requests by cURL too
        $curl = new CurlMock( fn( RequestInterface $request, array $options ) => Create::promiseFor( new Response( 200 ) )->then( function( $response ) use ( $request, $options ) {
            $this->sent[] = [$request, $options];
            return $response;
        } ) );

        $network = ( new Network( new FakeResolver() ) )->withHandler( HandlerStack::create( Proxy::wrapSync( $curl, $curl ) ) );
        $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), ['stream' => true] )->wait();

        $this->assertSame( 'https://api.example.com/v1', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testClosesConnectionsOfSharedHandlersIfInternalHostsAreAllowed() : void
    {
        $network = $this->network( true );

        $network->withInternalHosts()->handler()( new Request( 'GET', 'http://10.0.0.5/v1' ), [] )->wait();
        $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();

        // the handler is shared with the copy which only allows public addresses
        $this->assertTrue( $this->sent[0][1]['curl'][CURLOPT_FORBID_REUSE] );
        $this->assertArrayNotHasKey( CURLOPT_FORBID_REUSE, $this->sent[1][1]['curl'] );
    }


    public function testDoesNotTryNextAddressOfPostRequests() : void
    {
        $this->unreachable = 1;
        $stack = $this->network()->handler();

        try {
            $stack( new Request( 'POST', 'https://multi.example.com/v1' ), ['stream' => true, 'timeout' => 600, 'read_timeout' => 120] )->wait();
            $this->fail( 'Unreachable hosts must be rejected' );
        } catch( ConnectException $e ) {
            // the request may have been sent before the headers timed out
            $this->assertCount( 1, $this->sent );
            $this->assertGreaterThan( 120, $this->sent[0][1]['timeout'] );
        }
    }


    public function testDropsMiddlewareOfStack() : void
    {
        $stack = HandlerStack::create( fn( RequestInterface $request, array $options ) => Create::promiseFor( new Response( 200, [], $request->getHeaderLine( 'Authorization' ) ) ) );
        $stack->push( \GuzzleHttp\Middleware::mapRequest( fn( RequestInterface $request ) => $request->withHeader( 'Authorization', 'Bearer secret' ) ) );

        $network = ( new Network( new FakeResolver() ) )->withInternalHosts()->withHandler( $stack );

        $this->assertSame( 'Bearer secret', (string) $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait()->getBody() );
        $this->assertSame( '', (string) $network->withoutMiddleware()->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait()->getBody() );
    }


    public function testDisablesCurlProxiesAndSockets() : void
    {
        $stack = $this->network( true )->handler();
        $curl = [
            CURLOPT_PRE_PROXY => 'http://10.0.0.1', CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock', CURLOPT_ABSTRACT_UNIX_SOCKET => 'sock',
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_PORT => 8080, CURLOPT_URL => 'http://10.0.0.1/',
            CURLOPT_PROTOCOLS => CURLPROTO_ALL, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_ALL,
        ];

        $stack( new Request( 'GET', 'https://api.example.com/v1' ), ['curl' => $curl + [CURLOPT_PROXY => 'http://10.0.0.1']] )->wait();

        $this->assertSame( '', $this->sent[0][1]['curl'][CURLOPT_PROXY] );
        $this->assertSame( [], array_intersect_key( $this->sent[0][1]['curl'], $curl ) );
    }


    public function testRejectsStreamContextProxies() : void
    {
        $stack = $this->network()->handler();

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'through a proxy' );

        $stack( new Request( 'GET', 'https://api.example.com/v1' ), ['stream' => true, 'stream_context' => ['http' => ['proxy' => 'tcp://10.0.0.1:3128']]] );
    }


    public function testHandlerChangesAreNotShared() : void
    {
        $network = $this->network();
        $copy = $network->withInternalHosts();

        $network->handler()->push( fn( callable $next ) => fn( $request, array $options ) => throw new \RuntimeException( 'Changed stack is used' ) );

        $network->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();
        $copy->handler()( new Request( 'GET', 'https://api.example.com/v1' ), [] )->wait();

        $this->assertCount( 2, $this->sent );
    }


    public function testCopiesShareResolver() : void
    {
        $network = $this->network();
        $property = new \ReflectionProperty( Network::class, 'resolver' );

        $this->assertSame( $property->getValue( $network ), $property->getValue( $network->withInternalHosts() ) );
        $this->assertSame( $property->getValue( $network ), $property->getValue( clone $network ) );
    }


    public function testCopiesShareDefaultHandler() : void
    {
        $network = new Network();

        $this->assertSame( $this->base( $network ), $this->base( clone $network ) );
        $this->assertSame( $this->base( $network ), $this->base( $network->withInternalHosts( false ) ) );
    }


    public function testHostRestrictionsDontShareDefaultHandler() : void
    {
        $network = new Network();
        $internal = $network->withInternalHosts();

        $this->assertNotSame( $this->base( $network ), $this->base( $internal ) );
        $this->assertSame( $this->base( $internal ), $this->base( $internal->withInternalHosts() ) );
        $this->assertNotSame( $this->base( $network ), $this->base( $internal->withInternalHosts( false ) ) );
    }


    /**
     * Returns the innermost handler of a new handler stack of the network, which holds the cURL handles.
     */
    private function base( Network $network ) : mixed
    {
        return ( new \ReflectionProperty( HandlerStack::class, 'handler' ) )->getValue( $network->handler() );
    }


    private function network( bool $curl = false ) : Network
    {
        $handler = function( RequestInterface $request, array $options ) {
            $this->sent[] = [$request, $options];

            if( $this->unreachable-- > 0 ) {
                return Create::rejectionFor( new ConnectException( 'Connection refused', $request ) );
            }

            return Create::promiseFor( new Response( 200 ) );
        };

        return ( new Network( new FakeResolver() ) )->withHandler( HandlerStack::create( $curl ? new CurlMock( $handler ) : $handler ) );
    }
}
