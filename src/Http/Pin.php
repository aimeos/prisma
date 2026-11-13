<?php

namespace Aimeos\Prisma\Http;

use Aimeos\Prisma\Exceptions\PrismaException;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;


/**
 * Guzzle middleware which pins requests to the checked public IP addresses of their host.
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
 */
class Pin
{
    /** Methods whose requests can be sent again to the next address without side effects */
    private const IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'TRACE', 'PUT', 'DELETE'];

    /** cURL options which connect to other hosts, ports, sockets or protocols than the pinned addresses */
    private const UNSAFE = [
        'CURLOPT_ABSTRACT_UNIX_SOCKET', 'CURLOPT_CONNECT_TO', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_PORT',
        'CURLOPT_PRE_PROXY', 'CURLOPT_PROTOCOLS', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS',
        'CURLOPT_REDIR_PROTOCOLS_STR', 'CURLOPT_UNIX_SOCKET_PATH', 'CURLOPT_URL',
    ];

    private Resolver $resolver;
    private bool $curlOnly;
    private bool $curlUnstreamed;


    /**
     * Initializes the middleware for the handler the requests are sent by.
     *
     * @param Resolver $resolver Resolver of host names to public IP addresses
     * @param callable|null $handler Handler of the handler stack the middleware is added to
     */
    public function __construct( Resolver $resolver, ?callable $handler )
    {
        $proxy = $this->proxy( $handler );

        $this->resolver = $resolver;
        // Guzzle's default handler with cURL sends streamed requests by the StreamHandler only if URLs can be opened as files
        $this->curlUnstreamed = $proxy && ini_get( 'allow_url_fopen' );
        $this->curlOnly = $handler instanceof CurlHandler || $handler instanceof CurlMultiHandler || $proxy && !$this->curlUnstreamed;
    }


    /**
     * Returns the handler which pins the requests and passes them to the next one.
     *
     * @param callable $next Next handler
     * @return Closure Guzzle handler
     */
    public function __invoke( callable $next ) : Closure
    {
        return function( RequestInterface $request, array $options ) use ( $next ) {

            $uri = $request->getUri();
            $host = trim( $uri->getHost(), '[]' );
            $ips = $this->publicIps( $host, $uri->getScheme(), $options );

            if( $this->curlOnly || $this->curlUnstreamed && empty( $options['stream'] ) )
            {
                $port = $uri->getPort() ?? ( $uri->getScheme() === 'https' ? 443 : 80 );
                $addresses = implode( ',', array_map( $this->address( ... ), $ips ) );

                $options['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $addresses];
                // an empty proxy also disables the proxies cURL reads from the environment itself
                $options['curl'][CURLOPT_PROXY] = '';

                // Guzzle applies the cURL options over its own ones, e.g. the URL and the allowed protocols
                foreach( self::UNSAFE as $name )
                {
                    if( defined( $name ) ) {
                        unset( $options['curl'][constant( $name )] );
                    }
                }

                return $next( $request, $options );
            }

            // the StreamHandler reports timeouts after sending the request as connection errors too
            if( !in_array( $request->getMethod(), self::IDEMPOTENT, true ) ) {
                $ips = [reset( $ips )];
            }

            $options['stream_context']['ssl']['peer_name'] = $host;

            $timeout = (float) ( $options['timeout'] ?? 0 );

            return $this->connect( $next, $request, $options, $ips, $timeout > 0 ? microtime( true ) + $timeout : null );
        };
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


    /**
     * Tests if the handler is Guzzle's default handler, which chooses between cURL and the StreamHandler.
     *
     * @param callable|null $handler Handler of the handler stack
     * @return bool TRUE if the handler is created by Guzzle's Proxy class, FALSE if not
     */
    private function proxy( ?callable $handler ) : bool
    {
        return $handler instanceof Closure && ( new \ReflectionFunction( $handler ) )->getClosureScopeClass()?->getName() === Proxy::class;
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
}
