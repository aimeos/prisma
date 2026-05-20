<?php

namespace Aimeos\Prisma\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;


/**
 * HTTP client management for providers.
 */
trait HasHttpClient
{
    private Client $client;
    private ?HandlerStack $clientHandler = null;

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
        if( !isset( $this->client ) ) {
            $this->client = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $this->clientHandler] );
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
