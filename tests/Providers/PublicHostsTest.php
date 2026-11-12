<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;


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


    public function testPinsCurlRequestsWithPortAndIpv6() : void
    {
        $this->provider()->send( 'http://[2606:4700:4700::1111]:8080/v1' );

        $this->assertSame( ['2606:4700:4700::1111:8080:[2606:4700:4700::1111]'], $this->sent[0][1]['curl'][CURLOPT_RESOLVE] );
    }


    public function testRemovesCurlConnectTo() : void
    {
        $this->provider()
            ->withClientOptions( ['curl' => [CURLOPT_CONNECT_TO => ['api.example.com:443:127.0.0.1:443']]] )
            ->send( 'https://api.example.com/v1' );

        $this->assertArrayNotHasKey( CURLOPT_CONNECT_TO, $this->sent[0][1]['curl'] );
    }


    public function testPinsStreamRequests() : void
    {
        $provider = $this->provider();
        $stack = HandlerStack::create( $this->handler() );
        $stack->push( ( new \ReflectionMethod( $provider, 'pinMiddleware' ) )->invoke( $provider, true ) );

        $stack( new Request( 'POST', 'https://api.example.com:8443/v1/chat' ), [] )->wait();

        $this->assertSame( 'https://93.184.215.14:8443/v1/chat', (string) $this->sent[0][0]->getUri() );
        $this->assertSame( 'api.example.com:8443', $this->sent[0][0]->getHeaderLine( 'Host' ) );
        $this->assertSame( 'api.example.com', $this->sent[0][1]['stream_context']['ssl']['peer_name'] );
        $this->assertArrayNotHasKey( 'curl', $this->sent[0][1] );
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


    public function testInternalHostsAllowed() : void
    {
        $this->provider()->withInternalHosts()
            ->withClientOptions( ['proxy' => 'http://proxy.example.com:3128'] )
            ->send( 'http://127.0.0.1/' );

        $this->assertCount( 1, $this->sent );
        $this->assertArrayNotHasKey( 'curl', $this->sent[0][1] );
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
            return Create::promiseFor( array_shift( $this->responses ) ?? new Response( 200 ) );
        };
    }


    private function provider() : Base
    {
        $provider = new class( [] ) extends Base {
            public function __construct( array $config ) {}

            public function send( string $url ) : int
            {
                return $this->client()->get( $url )->getStatusCode();
            }

            // no DNS lookups in tests
            protected function resolve( string $host, bool $strict ) : ?string
            {
                return $host === 'api.example.com' ? '93.184.215.14' : parent::resolve( $host, $strict );
            }
        };

        return $provider->withClientHandler( HandlerStack::create( $this->handler() ) );
    }
}
