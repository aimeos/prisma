<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Http\Network;
use Aimeos\Prisma\Providers\Base;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Tests\Http\CurlMock;
use Tests\Http\FakeResolver;


class PublicHostsTest extends TestCase
{
    /** @var array<int, array{RequestInterface, array<string, mixed>}> */
    private array $sent = [];

    /** @var array<int, Response> */
    private array $responses = [];


    public function testPinsCurlRequests() : void
    {
        $this->provider()->send( 'https://api.example.com/v1/models' );

        $this->assertCount( 1, $this->sent );
        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
        $this->assertSame( 'api.example.com', $this->sent[0][0]->getUri()->getHost() );
    }


    public function testPinsAllAllowedAddresses() : void
    {
        $this->provider()->send( 'https://multi.example.com/v1/models' );

        $this->assertSame( ['multi.example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testPinsCurlRequestsWithPortAndIpv6() : void
    {
        $this->provider()->send( 'http://[2606:4700:4700::1111]:8080/v1' );

        $this->assertSame( ['2606:4700:4700::1111:8080:[2606:4700:4700::1111]'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testPinsRequestsOfOtherHandlersByAddress() : void
    {
        $provider = $this->provider( HandlerStack::create( $this->handler() ) );

        $this->assertSame( 200, $provider->send( 'https://api.example.com/v1' ) );
        $this->assertSame( 200, $provider->stream( 'https://api.example.com/v1' ) );

        $this->assertSame( 'https://93.184.215.14/v1', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( 'https://93.184.215.14/v1', (string) $this->sent[1][0]->getUri() );
        $this->assertSame( 'api.example.com', $this->sent[1][1]['stream_context']['ssl']['peer_name'] );
        $this->assertTrue( $this->sent[1][1]['stream'] );
    }


    public function testPinsStreamingByCurlHandlers() : void
    {
        $this->assertSame( 200, $this->provider()->stream( 'https://api.example.com/v1' ) );

        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
        $this->assertTrue( $this->sent[0][1]['stream'] );
    }


    public function testAllowsStreamingByCustomHandlersForInternalHosts() : void
    {
        $this->assertSame( 200, $this->provider()->withInternalHosts()->stream( 'https://api.example.com/v1' ) );
        $this->assertTrue( $this->sent[0][1]['stream'] );
    }


    public function testRemovesCurlConnectTo() : void
    {
        $this->provider()
            ->withClientOptions( ['curl' => [CURLOPT_CONNECT_TO => ['api.example.com:443:127.0.0.1:443']]] )
            ->send( 'https://api.example.com/v1' );

        $this->assertArrayNotHasKey( CURLOPT_CONNECT_TO, $this->sent[0][1]['curl'] );
    }


    #[TestWith( ['http://127.0.0.1/'] )]
    #[TestWith( ['http://localhost:11434/api/chat'] )]
    #[TestWith( ['http://10.0.0.1/'] )]
    #[TestWith( ['http://192.168.1.1/'] )]
    #[TestWith( ['http://100.100.100.200/'] )]
    #[TestWith( ['http://169.254.169.254/latest/meta-data/'] )]
    #[TestWith( ['http://[::1]/'] )]
    #[TestWith( ['http://[fd00::1]/'] )]
    #[TestWith( ['http://[::ffff:a9fe:a9fe]/'] )]
    #[TestWith( ['http://[64:ff9b::a9fe:a9fe]/'] )]
    #[TestWith( ['http://unresolvable.invalid/'] )]
    public function testRejectsNonPublicHosts( string $url ) : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'does not resolve to a public address' );

        try {
            $this->provider()->send( $url );
        } finally {
            $this->assertCount( 0, $this->sent );
        }
    }


    public function testAllowsPublicNat64() : void
    {
        $this->provider()->send( 'http://[64:ff9b::808:808]/' );

        $this->assertCount( 1, $this->sent );
    }


    public function testChecksRedirects() : void
    {
        $this->responses = [new Response( 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'] )];

        try {
            $this->provider()->send( 'https://api.example.com/v1' );
            $this->fail( 'Redirects to internal hosts must be rejected' );
        } catch( PrismaException $e ) {
            $this->assertStringContainsString( '169.254.169.254', $e->getMessage() );
            $this->assertCount( 1, $this->sent );
        }
    }


    public function testChecksRetries() : void
    {
        $this->responses = [new Response( 503 ), new Response( 200 )];

        $this->provider()->withClientRetry( 2, 0 )->send( 'https://api.example.com/v1' );

        $this->assertCount( 2, $this->sent );
        $this->assertArrayHasKey( CURLOPT_RESOLVE, $this->sent[1][1]['curl'] );
    }


    #[TestWith( ['http://proxy.example.com:3128'] )]
    #[TestWith( [['https' => 'http://proxy.example.com:3128']] )]
    #[TestWith( [['https' => 'http://proxy.example.com:3128', 'no' => ['other.example.com']]] )]
    public function testRejectsProxies( string|array $proxy ) : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'through a proxy' );

        $this->provider()->withClientOptions( ['proxy' => $proxy] )->send( 'https://api.example.com/v1' );
    }


    #[TestWith( [''] )]
    #[TestWith( [['http' => 'http://proxy.example.com:3128']] )]
    #[TestWith( [['https' => 'http://proxy.example.com:3128', 'no' => ['example.com']]] )]
    public function testAllowsDirectConnections( string|array $proxy ) : void
    {
        $this->provider()->withClientOptions( ['proxy' => $proxy] )->send( 'https://api.example.com/v1' );

        $this->assertCount( 1, $this->sent );
    }


    public function testAppliesToCreatedClients() : void
    {
        $provider = $this->provider();

        try {
            $provider->send( 'http://127.0.0.1/' );
            $this->fail( 'Internal hosts must be rejected by default' );
        } catch( PrismaException $e ) {
            $this->assertCount( 0, $this->sent );
        }

        $provider->withInternalHosts()->send( 'http://127.0.0.1/' );
        $this->assertCount( 1, $this->sent );

        $this->expectException( PrismaException::class );
        $provider->withInternalHosts( false )->send( 'http://127.0.0.1/' );
    }


    public function testDoesNotChangeSharedStacks() : void
    {
        $stack = HandlerStack::create( $this->handler() );
        $public = $this->provider( $stack )->withClientRetry( 3, 0 );
        $other = $this->provider( $stack )->withClientRetry( 3, 0 );

        $public->send( 'https://api.example.com/v1' );
        $other->send( 'https://api.example.com/v1' );
        $this->sent = [];

        $this->responses = [new Response( 503 ), new Response( 503 ), new Response( 503 )];
        $this->assertSame( 503, $other->withInternalHosts()->send( 'http://127.0.0.1/' ) );
        $this->assertCount( 3, $this->sent );

        $this->expectException( PrismaException::class );
        $public->send( 'http://127.0.0.1/' );
    }


    public function testInternalHostsAllowed() : void
    {
        $this->provider()->withInternalHosts()
            ->withClientOptions( ['proxy' => 'http://proxy.example.com:3128'] )
            ->send( 'http://127.0.0.1/' );

        $this->assertCount( 1, $this->sent );
        $this->assertSame( [CURLOPT_FORBID_REUSE => true], $this->sent[0][1]['curl'] );
    }


    public function testFilesUseNetworkOfProvider() : void
    {
        $stack = HandlerStack::create( new CurlMock( $this->handler() ) );
        $stack->push( Middleware::mapRequest( fn( RequestInterface $request ) => $request->withHeader( 'X-Token', 'secret' ) ) );

        $provider = $this->provider( $stack )->withClientOptions( ['headers' => ['Authorization' => 'Bearer secret']] );
        $provider->send( 'https://api.example.com/v1' );

        $this->responses = [new Response( 200, [], 'image' )];
        $this->assertSame( 'image', $provider->file( 'https://api.example.com/image.png' )->binary() );

        // same handler, pin and cached addresses but no client options or middleware adding credentials
        $this->assertSame( ['api.example.com:443:93.184.215.14'], $this->sent[1][1]['curl'][CURLOPT_RESOLVE] );
        $this->assertSame( [], $this->sent[1][0]->getHeader( 'Authorization' ) );
        $this->assertSame( 'secret', $this->sent[0][0]->getHeaderLine( 'X-Token' ) );
        $this->assertSame( [], $this->sent[1][0]->getHeader( 'X-Token' ) );
        $this->assertSame( 1, $provider->resolver()->lookups );
    }


    public function testFilesShareDnsCache() : void
    {
        $provider = $this->provider();
        $this->responses = [new Response( 200, [], 'image' )];

        $provider->file( 'https://multi.example.com/image.png' )->binary();
        $provider->send( 'https://multi.example.com/v1' );

        $this->assertSame( 1, $provider->resolver()->lookups );
    }


    public function testCopiesHandlerStack() : void
    {
        $stack = HandlerStack::create( $this->handler() );
        $provider = $this->provider( $stack );

        $stack->push( fn( callable $next ) => fn( $request, array $options ) => throw new \RuntimeException( 'Changed stack is used' ) );

        $this->assertSame( 200, $provider->send( 'https://api.example.com/v1' ) );
    }


    public function testAppliesLaterClientChanges() : void
    {
        $provider = $this->provider();
        $provider->send( 'https://api.example.com/v1' );

        $this->responses = [new Response( 503 ), new Response( 200 )];
        $provider->withClientOptions( ['headers' => ['X-Test' => 'yes']] )->withClientRetry( 2, 0 );

        $this->assertSame( 200, $provider->send( 'https://api.example.com/v1' ) );
        $this->assertSame( 'yes', $this->sent[1][0]->getHeaderLine( 'X-Test' ) );
        $this->assertCount( 3, $this->sent );
    }


    public function testNetworkChangesAreNotShared() : void
    {
        $provider = $this->provider()->withInternalHosts();
        $file = $provider->file( 'http://127.0.0.1/image.png' )->withInternalHosts( false );

        $this->assertSame( 200, $provider->send( 'http://127.0.0.1/' ) );

        $this->expectException( PrismaException::class );
        $file->binary();
    }


    public function testProviderRejectsInternalBaseUrl() : void
    {
        $provider = \Aimeos\Prisma\Prisma::image()
            ->using( 'replicate', ['api_key' => 'test', 'url' => 'http://169.254.169.254/'] )
            ->withClientHandler( HandlerStack::create( $this->handler() ) )
            ;

        try {
            $provider->imagine( 'A sunflower' );
            $this->fail( 'Requests to internal base URLs must be rejected' );
        } catch( PrismaException $e ) {
            $this->assertStringContainsString( 'does not resolve to a public address', $e->getMessage() );
            $this->assertCount( 0, $this->sent );
        }
    }


    private function handler() : \Closure
    {
        return function( RequestInterface $request, array $options ) {
            $this->sent[] = [$request, $options];
            $response = array_shift( $this->responses ) ?? new Response( 200 );

            // file downloads pass a sink the body must be written to
            if( ( $options['sink'] ?? null ) instanceof StreamInterface ) {
                $options['sink']->write( (string) $response->getBody() );
            }

            return Create::promiseFor( $response );
        };
    }


    private function provider( ?HandlerStack $stack = null ) : PublicHostsProvider
    {
        return ( new PublicHostsProvider( [] ) )->withClientHandler( $stack ?? HandlerStack::create( new CurlMock( $this->handler() ) ) );
    }
}


class PublicHostsProvider extends Base
{
    public function __construct( array $config )
    {
        ( new \ReflectionProperty( Base::class, 'network' ) )->setValue( $this, new Network( new FakeResolver() ) );
    }


    public function resolver() : FakeResolver
    {
        /** @var FakeResolver */
        return ( new \ReflectionProperty( Network::class, 'resolver' ) )->getValue( $this->network() );
    }


    /**
     * @param array<string, mixed> $options
     */
    public function send( string $url, array $options = [] ) : int
    {
        return $this->client()->get( $url, $options )->getStatusCode();
    }


    public function file( string $url ) : File
    {
        return $this->urlFile( $url );
    }


    public function stream( string $url ) : int
    {
        return $this->client()->get( $url, ['stream' => true] )->getStatusCode();
    }
}
