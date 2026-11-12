<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Http\Network;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
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
    private Network $network;

    /** @var callable|null */
    private $retryMiddleware = null;

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
        $this->network = $this->network()->withHandler( $stack );
        unset( $this->client );

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
        unset( $this->client );

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
        unset( $this->client );

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
     * resolves the host itself. For the same reason, proxies cURL reads from the environment are
     * disabled and the CURLOPT_CONNECT_TO, CURLOPT_PRE_PROXY, CURLOPT_UNIX_SOCKET_PATH and
     * CURLOPT_ABSTRACT_UNIX_SOCKET options are removed. Handlers set by withClientHandler() must
     * be Guzzle's cURL handlers or its StreamHandler, because other handlers get requests to the
     * IP address, which fail for HTTPS.
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
        $this->network = $this->network()->withInternalHosts( $allow );
        unset( $this->client );

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
            unset( $this->client );
        }

        return $this;
    }


    /**
     * Returns the HTTP client, creating it on first use.
     *
     * Requests with the "stream" option are sent by Guzzle's StreamHandler, all others by cURL:
     * only the StreamHandler honors the per-read inactivity timeout (read_timeout) and exposes
     * the "timed_out" stream metadata that readLines() relies on to detect a stalled stream -
     * cURL ignores read_timeout and downloads the whole response before it's returned.
     *
     * @return Client Guzzle HTTP client
     */
    protected function client() : Client
    {
        if( !isset( $this->client ) ) {
            $handler = $this->network()->handler( $this->retryMiddleware );
            $this->client = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $handler] );
        }

        return $this->client;
    }


    /**
     * Returns the network access of the provider, which files created by urlFile() copy.
     *
     * @return Network Network access of the provider
     */
    protected function network() : Network
    {
        return $this->network ??= new Network();
    }


    /**
     * Returns a lazy file for a URL returned by the API.
     *
     * The file is downloaded with the handler and host restrictions of the provider but neither
     * its client options nor the middleware of its handler stack, which may add credentials.
     * Later changes of the provider don't affect the file.
     *
     * @template T of File
     * @param string $url File URL returned by the API
     * @param class-string<T> $class File class, e.g. Image::class or Video::class
     * @param string|null $mimeType Content type of the file if known
     * @return T File downloaded on first access
     */
    protected function urlFile( string $url, string $class = File::class, ?string $mimeType = null ) : File
    {
        return $class::fromUrl( $url, $mimeType )->withNetwork( $this->network()->withoutMiddleware() );
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
            unset( $this->client );
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
