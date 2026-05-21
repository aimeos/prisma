<?php

namespace Aimeos\Prisma\Concerns;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;


/**
 * HTTP client management for providers.
 */
trait HasHttpClient
{
    private Client $client;
    private ?HandlerStack $clientHandler = null;

    /** @var callable|null */
    private $retryMiddleware = null;

    /** @var array<string, mixed> */
    private array $clientOptions = ['connect_timeout' => 10, 'timeout' => 60];


    /**
     * Sets the Guzzle handler stack for the HTTP client.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return self
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
     * @return self
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
     * @return self
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
     * Sets the base URL for the HTTP client.
     *
     * @param mixed $url Base URL string
     * @return self
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
        if( !isset( $this->client ) )
        {
            $handler = $this->clientHandler;

            if( $this->retryMiddleware )
            {
                $handler = $handler ?? HandlerStack::create();
                $handler->push( $this->retryMiddleware );
            }

            $this->client = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $handler] );
        }

        return $this->client;
    }


    /**
     * Sets a default HTTP header for requests.
     *
     * @param string $name Header name
     * @param mixed $value Header value string
     * @return self
     */
    protected function header( string $name, mixed $value ) : self
    {
        if( is_string( $value ) ) {
            $this->clientOptions['headers'][$name] = $value;
        }

        return $this;
    }
}
