<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Files\File;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


/**
 * HTTP client management for providers.
 */
trait HasHttpClient
{
    use ResolvesHosts;

    private Client $client;
    private Client $streamClient;
    private ?HandlerStack $clientHandler = null;

    /** @var callable|null */
    private $retryMiddleware = null;

    private bool $retryApplied = false;
    private bool $publicOnly = true;
    private bool $publicApplied = false;

    /** @var array<string, mixed> */
    private array $clientOptions = ['connect_timeout' => 10, 'timeout' => 60];


    /**
     * Sets the Guzzle handler stack for the HTTP client.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return self Same instance for fluent chaining
     */
    public function withClientHandler( HandlerStack $stack ) : self
    {
        $this->clientHandler = $stack;
        return $this;
    }


    /**
     * Merges additional options into the HTTP client configuration.
     *
     * @param array<string, mixed> $options Guzzle client options
     * @return self Same instance for fluent chaining
     */
    public function withClientOptions( array $options ) : self
    {
        $this->clientOptions = array_replace_recursive( $this->clientOptions, $options );
        return $this;
    }


    /**
     * Configures automatic retry with backoff for failed HTTP requests.
     *
     * @param int $maxAttempts Total number of attempts including the initial request
     * @param \Closure|int $delayMs Fixed delay in ms or closure: fn(int $attempt, ResponseInterface $response): int
     * @param \Closure|null $when Retry condition: fn(ResponseInterface $response, int $attempt): bool
     * @return self Same instance for fluent chaining
     */
    public function withClientRetry( int $maxAttempts = 3, Closure|int $delayMs = 100, ?Closure $when = null ) : self
    {
        $decider = function( int $retries, $request, ?ResponseInterface $response, ?\Exception $exception ) use ( $maxAttempts, $when ) : bool {
            if( !$response || $retries >= $maxAttempts - 1 ) {
                return false;
            }

            if( $exception instanceof ConnectException ) {
                return true;
            }

            return $when
                ? (bool) $when( $response, $retries + 1 )
                : in_array( $response->getStatusCode(), [429, 500, 502, 503, 504, 529] );
        };

        $delay = function( int $retries, ?ResponseInterface $response ) use ( $delayMs ) : int {
            return $delayMs instanceof Closure ? $delayMs( $retries + 1, $response ) : $delayMs;
        };

        $this->retryMiddleware = Middleware::retry( $decider, $delay );
        return $this;
    }


    /**
     * Allows requests to hosts resolving to internal IP addresses.
     *
     * By default, all requests are restricted to hosts resolving to public IP addresses, so
     * users who can change the base URL or other provider settings can't reach internal
     * services, e.g. the loopback interface, private networks or the metadata service of cloud
     * servers. The host of every request, redirect and retry is resolved and the connection is
     * pinned to the checked address, so DNS rebinding can't switch to an internal address
     * between check and request. Requests through a proxy are rejected too because the proxy
     * resolves the host itself and HTTP handlers set by withClientHandler() must be cURL based
     * to keep the DNS pin.
     *
     * Allow internal hosts for local or private endpoints like Ollama, LM Studio or vLLM, API
     * gateways in the own network or if requests must be sent through a proxy. Only do that if
     * the provider settings can't be changed by untrusted users.
     *
     * @param bool $allow TRUE to allow internal hosts and proxies, FALSE to allow public hosts only
     * @return self Same instance for fluent chaining
     */
    public function withInternalHosts( bool $allow = true ) : self
    {
        $this->publicOnly = !$allow;
        unset( $this->client, $this->streamClient );

        return $this;
    }


    /**
     * Returns the absolute URL of an API path, resolved against the base URL like requests are.
     *
     * @param string $path Path relative to the base URL
     * @return string Absolute URL
     */
    protected function apiUrl( string $path ) : string
    {
        return (string) UriResolver::resolve( Utils::uriFor( $this->clientOptions['base_uri'] ?? '' ), new Uri( $path ) );
    }


    /**
     * Sets the base URL for the HTTP client.
     *
     * @param mixed $url Base URL string
     * @return self Same instance for fluent chaining
     */
    protected function baseUrl( mixed $url ) : self
    {
        if( is_string( $url ) ) {
            $this->clientOptions['base_uri'] = $url;
        }

        return $this;
    }


    /**
     * Returns the HTTP client, creating it on first use.
     *
     * @return Client Guzzle HTTP client
     */
    protected function client() : Client
    {
        if( !isset( $this->client ) ) {
            $this->client = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $this->handler( null )] );
        }

        return $this->client;
    }


    /**
     * Returns the HTTP client used for streaming requests, creating it on first use.
     *
     * Streaming uses Guzzle's StreamHandler rather than the default cURL handler: only the
     * StreamHandler honors the per-read inactivity timeout (read_timeout) and exposes the
     * "timed_out" stream metadata that readLines() relies on to detect a stalled stream - cURL
     * ignores read_timeout, so a stalled stream could otherwise pin the process. A handler set
     * explicitly via withClientHandler() (e.g. a test mock) is respected unchanged and shared with
     * the non-streaming client. Configured retry behaviour applies to both clients alike.
     *
     * @return Client Guzzle HTTP client for streaming
     */
    protected function streamClient() : Client
    {
        if( !isset( $this->streamClient ) ) {
            $this->streamClient = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $this->handler( new StreamHandler() )] );
        }

        return $this->streamClient;
    }


    /**
     * Builds a handler stack for a client, adding the retry middleware when configured.
     *
     * A handler set explicitly via withClientHandler() backs both the streaming and non-streaming
     * client; it is augmented with the retry middleware only once so the shared stack is never
     * wrapped twice. Otherwise a fresh stack is created around the given base handler - the default
     * (cURL) handler for normal requests, the StreamHandler for streaming - and the retry middleware
     * is pushed onto each so streamed and non-streamed requests retry identically.
     *
     * @param callable|null $base Base handler for a self-created stack, or null for Guzzle's default
     * @return HandlerStack Configured handler stack
     */
    private function handler( ?callable $base ) : HandlerStack
    {
        if( isset( $this->clientHandler ) )
        {
            if( $this->retryMiddleware && !$this->retryApplied )
            {
                $this->clientHandler->push( $this->retryMiddleware );
                $this->retryApplied = true;
            }

            if( $this->publicOnly && !$this->publicApplied )
            {
                $this->clientHandler->push( $this->pinMiddleware( false ), 'prisma_pin' );
                $this->publicApplied = true;
            }
            elseif( !$this->publicOnly && $this->publicApplied )
            {
                $this->clientHandler->remove( 'prisma_pin' );
                $this->publicApplied = false;
            }

            return $this->clientHandler;
        }

        // Guzzle falls back to the StreamHandler without cURL, which ignores the cURL DNS pin
        if( $this->publicOnly && $base === null && !function_exists( 'curl_exec' ) ) {
            $base = new StreamHandler();
        }

        $handler = $base ? HandlerStack::create( $base ) : HandlerStack::create();

        if( $this->retryMiddleware ) {
            $handler->push( $this->retryMiddleware );
        }

        // Pushed last, so it's the innermost middleware and checks every redirect and retry too
        if( $this->publicOnly ) {
            $handler->push( $this->pinMiddleware( $base instanceof StreamHandler ), 'prisma_pin' );
        }

        return $handler;
    }


    /**
     * Returns the middleware which pins requests to the checked public IP address of the host.
     *
     * cURL connects to the pinned address by CURLOPT_RESOLVE. The StreamHandler has no such
     * option, so the request is sent to the IP address instead while the Host header and the
     * TLS peer name (used for SNI and certificate validation) keep the original host name.
     *
     * @param bool $stream TRUE for the StreamHandler, FALSE for cURL based handlers
     * @return \Closure Guzzle middleware
     */
    private function pinMiddleware( bool $stream ) : \Closure
    {
        return fn( callable $next ) => function( RequestInterface $request, array $options ) use ( $next, $stream ) {

            $uri = $request->getUri();
            $host = trim( $uri->getHost(), '[]' );
            $scheme = $uri->getScheme();

            if( !in_array( $scheme, ['http', 'https'], true ) || $host === '' || !( $ip = $this->resolve( $host, true ) ) ) {
                throw new \Aimeos\Prisma\Exceptions\PrismaException( sprintf( 'Host "%s" does not resolve to a public address, use withInternalHosts() to allow internal hosts', $host ) );
            }

            if( $this->proxied( $host, $scheme, $options['proxy'] ?? null ) ) {
                throw new \Aimeos\Prisma\Exceptions\PrismaException( sprintf( 'Requests to "%s" through a proxy are only allowed after calling withInternalHosts()', $host ) );
            }

            $addr = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;

            if( $stream )
            {
                $request = $request->withUri( $uri->withHost( $addr ), true );
                $options['stream_context']['ssl']['peer_name'] = $host;
            }
            elseif( !defined( 'CURLOPT_RESOLVE' ) )
            {
                throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Restricting requests to public hosts requires the cURL extension, use withInternalHosts() otherwise' );
            }
            else
            {
                $port = $uri->getPort() ?? ( $scheme === 'https' ? 443 : 80 );
                $options['curl'][CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $addr];
                unset( $options['curl'][CURLOPT_CONNECT_TO] );
            }

            return $next( $request, $options );
        };
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
     * Returns a file for a URL returned by the API.
     *
     * @template T of File
     * @param string $url File URL returned by the API
     * @param class-string<T> $class File class, e.g. Image::class or Video::class
     * @param string|null $mimeType Content type of the file if known
     * @return T File downloaded on first access
     */
    protected function urlFile( string $url, string $class = File::class, ?string $mimeType = null ) : File
    {
        return $class::fromUrl( $url, $mimeType, !$this->publicOnly );
    }


    /**
     * Sets a default HTTP header for requests.
     *
     * @param string $name Header name
     * @param mixed $value Header value string
     * @return self Same instance for fluent chaining
     */
    protected function header( string $name, mixed $value ) : self
    {
        if( is_string( $value ) ) {
            $this->clientOptions['headers'][$name] = $value;
        }

        return $this;
    }


    /**
     * Returns the URI of a job URL the credentials may be sent to.
     *
     * A status, result or cancel URL is either data the provider returned or a job ID the caller
     * passed, so the host alone doesn't make it safe: the API key must only go to the API hosts
     * and to the endpoint the job needs, not to any other endpoint of these hosts. Both halves of
     * that rule are checked here so no caller can remember one and forget the other. The pattern
     * matches the end of the path only, so an API gateway in front of the provider that adds a
     * path prefix still works.
     *
     * @param string $url Absolute URL returned by the provider or passed by the caller
     * @param string $pattern Regular expression the end of the URL path must match
     * @param string $hosts Regular expression of the HTTPS hosts trusted too, e.g. regional API hosts
     * @param string $error Message of the exception thrown for URLs that aren't allowed
     * @return Uri Validated URL
     * @throws \Aimeos\Prisma\Exceptions\BadRequestException If the URL isn't the allowed endpoint of an API host
     */
    protected function jobUrl( string $url, string $pattern, string $hosts = '', string $error = 'Invalid provider URL' ) : Uri
    {
        $uri = $this->trusted( $url, $hosts ) ? new Uri( $url ) : null;

        if( $uri === null || !preg_match( $pattern, $uri->getPath() ) ) {
            throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
        }

        return $uri;
    }


    /**
     * Tests if the URL points to the API host, so credentials can be sent to it.
     *
     * Only list the API hosts of the provider, not its whole domain: other subdomains like
     * documentation or status pages are often run by third parties.
     *
     * @param string $url Absolute URL, e.g. a status URL returned by the provider or passed by the caller
     * @param string $hosts Regular expression of the HTTPS hosts trusted too, e.g. regional API hosts
     * @return bool TRUE if the URL uses the scheme, host and port of the base URL or is one of the HTTPS hosts
     * @see jobUrl() Use it instead to allow the endpoints of the host the credentials may go to
     */
    protected function trusted( string $url, string $hosts = '' ) : bool
    {
        try {
            $uri = new Uri( $url );
            $base = Utils::uriFor( $this->clientOptions['base_uri'] ?? '' );
        } catch( \InvalidArgumentException $e ) {
            return false;
        }

        $host = $uri->getHost();

        if( $host === '' || $uri->getUserInfo() !== '' ) {
            return false;
        }

        if( $host === $base->getHost() && $uri->getScheme() === $base->getScheme() && $uri->getPort() === $base->getPort() ) {
            return true;
        }

        return $hosts !== '' && preg_match( $hosts, $host ) === 1
            && $uri->getScheme() === 'https' && $uri->getPort() === null;
    }
}
