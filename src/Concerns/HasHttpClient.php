<?php

namespace Aimeos\Prisma\Concerns;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;


/**
 * HTTP client management for providers.
 */
trait HasHttpClient
{
    private Client $client;
    private Client $streamClient;
    private ?HandlerStack $clientHandler = null;

    /** @var callable|null */
    private $retryMiddleware = null;

    private bool $retryApplied = false;

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
                : in_array( $response->getStatusCode(), [429, 500, 502, 503, 504] );
        };

        $delay = function( int $retries, ?ResponseInterface $response ) use ( $delayMs ) : int {
            return $delayMs instanceof Closure ? $delayMs( $retries + 1, $response ) : $delayMs;
        };

        $this->retryMiddleware = Middleware::retry( $decider, $delay );
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

            return $this->clientHandler;
        }

        $handler = $base ? HandlerStack::create( $base ) : HandlerStack::create();

        if( $this->retryMiddleware ) {
            $handler->push( $this->retryMiddleware );
        }

        return $handler;
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
