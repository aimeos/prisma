<?php

namespace Aimeos\Prisma\Http;

use Aimeos\Prisma\Exceptions\PrismaException;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;


/**
 * Network access of a provider and the files it returns.
 *
 * Holds the Guzzle handler stack, whether internal hosts are allowed and the resolver of the
 * hosts. Providers hand a copy to the files they create, so downloads of results use the same
 * handler and host restrictions as the API requests, but never the client options of the
 * provider like its credentials. Instances are immutable except for the DNS cache of their
 * resolver, which copies share because it only contains checked public addresses.
 *
 * Without a handler stack set via withHandler(), copies with the same host restriction share
 * a default stack, so its cURL handles keep connections open across requests of the provider
 * and its files. Copies with a different host restriction get their own one: cURL reuses open
 * connections by host name, so a connection to an internal address must never be reused by a
 * copy which only allows public addresses. Copies of a stack set via withHandler() share its
 * handler, so their cURL connections are closed after each request if internal hosts are allowed.
 */
class Network
{
    private Resolver $resolver;
    private HandlerStack $default;
    private ?HandlerStack $stack = null;
    private bool $strict = true;


    /**
     * Initializes the instance.
     *
     * @param Resolver|null $resolver Resolver of host names to public IP addresses
     */
    public function __construct( ?Resolver $resolver = null )
    {
        $this->resolver = $resolver ?? new Resolver();
        $this->default = HandlerStack::create();
    }


    /**
     * Copies the handler stack set by withHandler(), so copies like those of files don't share it.
     * The default stack and the resolver are shared, so connections and resolved hosts are reused.
     */
    public function __clone()
    {
        if( $this->stack ) {
            $this->stack = clone $this->stack;
        }
    }


    /**
     * Keeps the host restriction only because handler stacks contain closures and resolved
     * addresses expire.
     *
     * @return array<string, mixed> Properties to serialize
     */
    public function __serialize() : array
    {
        return ['strict' => $this->strict];
    }


    /**
     * Restores the host restriction.
     *
     * @param array<string, mixed> $data Serialized properties
     */
    public function __unserialize( array $data ) : void
    {
        $this->resolver = new Resolver();
        $this->default = HandlerStack::create();
        $this->strict = (bool) ( $data['strict'] ?? true );
    }


    /**
     * Returns a new handler stack with the pin as innermost middleware if internal hosts aren't allowed.
     *
     * The handler stack set via withHandler() or the default stack is copied, so the middleware is
     * never added to a stack shared with other clients.
     *
     * @param callable|null $middleware Additional middleware like retries, added before the pin
     * @return HandlerStack Guzzle handler stack
     */
    public function handler( ?callable $middleware = null ) : HandlerStack
    {
        $handler = clone ( $this->stack ?? $this->default );

        if( $middleware ) {
            $handler->push( $middleware );
        }

        // Pushed last, so it's the innermost middleware and checks every redirect and retry too
        if( $this->strict ) {
            $handler->push( $this->pin( $handler ), 'prisma_pin' );
        } elseif( $this->stack ) {
            $handler->push( $this->unshared(), 'prisma_unshared' );
        }

        return $handler;
    }


    /**
     * Returns a copy using the given Guzzle handler stack.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return static New instance
     */
    public function withHandler( HandlerStack $stack ) : static
    {
        // copied, so later changes of the caller's stack don't affect this instance
        $network = clone $this;
        $network->stack = clone $stack;

        return $network;
    }


    /**
     * Returns a copy which allows or denies internal hosts and proxies.
     *
     * @param bool $allow TRUE to allow internal hosts and proxies, FALSE to allow public hosts only
     * @return static New instance
     */
    public function withInternalHosts( bool $allow = true ) : static
    {
        $network = clone $this;

        // connections opened by one host restriction must not be reused by the other one
        if( $network->strict === $allow ) {
            $network->default = HandlerStack::create();
        }

        $network->strict = !$allow;

        return $network;
    }


    /**
     * Returns a copy whose handler stack contains the handler of the stack set via withHandler()
     * but none of its middleware, which may add credentials to the requests.
     *
     * @return static New instance
     */
    public function withoutMiddleware() : static
    {
        $network = clone $this;

        if( $this->stack ) {
            $network->stack = HandlerStack::create( self::base( $this->stack ) );
        }

        return $network;
    }


    /**
     * Returns the middleware which pins requests to the checked public IP addresses of the host.
     *
     * cURL requests are pinned by CURLOPT_RESOLVE to all addresses. Guzzle's default handler
     * sends streamed requests by its StreamHandler, which has no such option, like all other
     * handlers which aren't cURL handlers. Their requests are sent to the IP address
     * instead while the Host header and the TLS peer name (used for SNI and certificate
     * validation) keep the original host name, so they are pinned even if the handler resolves
     * the host itself. Only the StreamHandler validates the certificate against the peer name,
     * so other handlers reject these HTTPS requests. Requests which can be repeated safely are
     * sent to the next address if the connection fails. Requests through a proxy are rejected
     * because the proxy resolves the host itself.
     *
     * @param HandlerStack $stack Handler stack the middleware is added to
     * @return Closure Guzzle middleware
     */
    protected function pin( HandlerStack $stack ) : Closure
    {
        $handler = self::base( $stack );
        $proxy = $handler instanceof Closure && ( new \ReflectionFunction( $handler ) )->getClosureScopeClass()?->getName() === Proxy::class;
        // Guzzle's default handler with cURL sends streamed requests by the StreamHandler only if URLs can be opened as files
        $default = $proxy && ini_get( 'allow_url_fopen' );
        $curlOnly = $handler instanceof CurlHandler || $handler instanceof CurlMultiHandler || $proxy && !$default;

        return fn( callable $next ) => function( RequestInterface $request, array $options ) use ( $next, $curlOnly, $default ) {

            $uri = $request->getUri();
            $host = trim( $uri->getHost(), '[]' );
            $ips = $this->publicIps( $host, $uri->getScheme(), $options );

            if( $curlOnly || $default && empty( $options['stream'] ) )
            {
                $port = $uri->getPort() ?? ( $uri->getScheme() === 'https' ? 443 : 80 );
                $addresses = implode( ',', array_map( $this->address( ... ), $ips ) );

                $options['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $addresses];
                // an empty proxy also disables the proxies cURL reads from the environment itself
                $options['curl'][CURLOPT_PROXY] = '';

                // connect to other hosts or sockets than the pinned addresses
                unset(
                    $options['curl'][CURLOPT_CONNECT_TO], $options['curl'][CURLOPT_PRE_PROXY],
                    $options['curl'][CURLOPT_UNIX_SOCKET_PATH], $options['curl'][CURLOPT_ABSTRACT_UNIX_SOCKET]
                );

                return $next( $request, $options );
            }

            // the StreamHandler reports timeouts after sending the request as connection errors too
            if( !in_array( $request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'], true ) ) {
                $ips = [reset( $ips )];
            }

            $options['stream_context']['ssl']['peer_name'] = $host;

            $timeout = (float) ( $options['timeout'] ?? 0 );

            return $this->connect( $next, $request, $options, $ips, $timeout > 0 ? microtime( true ) + $timeout : null );
        };
    }


    /**
     * Sends the request to the first IP address and to the next one if the connection fails.
     *
     * The StreamHandler ignores the connect timeout and uses the timeout for connecting and
     * waiting for the response headers. Each attempt with another address left is limited by
     * the read timeout instead, because no data arrives before the headers either, and all
     * attempts share the timeout, so an address dropping all packets doesn't use up the time of
     * the other addresses.
     *
     * @param callable $next Next handler
     * @param RequestInterface $request Request to the original host
     * @param array<string, mixed> $options Guzzle request options
     * @param non-empty-array<int, string> $ips Public IP addresses of the host
     * @param float|null $deadline Time in seconds when all attempts time out, NULL for no timeout
     * @return PromiseInterface Promise of the response
     */
    private function connect( callable $next, RequestInterface $request, array $options, array $ips, ?float $deadline ) : PromiseInterface
    {
        $ip = array_shift( $ips );
        $uri = $request->getUri()->withHost( $this->address( $ip ) );

        $limits = array_filter( [
            $deadline !== null ? max( $deadline - microtime( true ), 0.001 ) : 0,
            $ips !== [] ? (float) ( $options['read_timeout'] ?? 0 ) : 0,
        ] );

        if( $limits !== [] ) {
            $options['timeout'] = min( $limits );
        }

        return $next( $request->withUri( $uri, true ), $options )->otherwise(
            fn( $e ) => $e instanceof ConnectException && $ips !== [] && ( $deadline === null || microtime( true ) < $deadline )
                ? $this->connect( $next, $request, $options, $ips, $deadline )
                : Create::rejectionFor( $e )
        );
    }


    /**
     * Returns the middleware which closes cURL connections after the request, so copies sharing
     * the handler which only allow public addresses can't reuse a connection to an internal one.
     *
     * @return Closure Guzzle middleware
     */
    protected function unshared() : Closure
    {
        return fn( callable $next ) => function( RequestInterface $request, array $options ) use ( $next ) {
            $options['curl'][CURLOPT_FORBID_REUSE] = true;
            return $next( $request, $options );
        };
    }


    /**
     * Returns the handler of the handler stack.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return callable|null Handler of the stack or NULL if none is set
     */
    private static function base( HandlerStack $stack ) : ?callable
    {
        // HandlerStack has no getter for its handler
        $handler = ( new \ReflectionProperty( HandlerStack::class, 'handler' ) )->getValue( $stack );
        return is_callable( $handler ) ? $handler : null;
    }


    /**
     * Returns the IP address in the notation used in URLs and cURL resolve entries.
     *
     * @param string $ip IPv4 or IPv6 address
     * @return string IPv4 address or IPv6 address in brackets
     */
    private function address( string $ip ) : string
    {
        return str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
    }


    /**
     * Returns the public IP addresses a request is allowed to connect to.
     *
     * @param string $host Host name of the request
     * @param string $scheme URL scheme of the request
     * @param array<string, mixed> $options Guzzle request options
     * @return non-empty-array<int, string> Public IP addresses of the host
     * @throws PrismaException If the URL has no host, the request uses a proxy or the host isn't public
     */
    private function publicIps( string $host, string $scheme, array $options ) : array
    {
        // Guzzle's handlers only send HTTP and HTTPS requests
        if( $host === '' ) {
            throw new PrismaException( 'Requests to URLs without a host are not allowed' );
        }

        if( $this->proxied( $host, $scheme, $options['proxy'] ?? null ) || !empty( $options['stream_context']['http']['proxy'] ) ) {
            throw new PrismaException( sprintf( 'Requests to "%s" through a proxy are only allowed if internal hosts are allowed', $host ) );
        }

        if( ( $ips = $this->resolver->resolve( $host ) ) === [] ) {
            throw new PrismaException( sprintf( 'Host "%s" does not resolve to a public address', $host ) );
        }

        return $ips;
    }


    /**
     * Tests if the request is sent through a proxy.
     *
     * @param string $host Host name of the request
     * @param string $scheme URL scheme of the request
     * @param mixed $proxy Value of the Guzzle "proxy" option, set from the environment by default
     * @return bool TRUE if the request uses a proxy, FALSE if it connects directly
     */
    private function proxied( string $host, string $scheme, mixed $proxy ) : bool
    {
        if( !is_array( $proxy ) ) {
            return is_string( $proxy ) && $proxy !== '';
        }

        if( empty( $proxy[$scheme] ) ) {
            return false;
        }

        return !is_array( $proxy['no'] ?? null ) || !\GuzzleHttp\Utils::isHostInNoProxy( $host, $proxy['no'] );
    }
}
