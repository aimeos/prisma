<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Files\File;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Client;


abstract class Base implements Provider
{
    private $client;
    private $clientOptions = [];
    private $clientHandler = null;
    private $systemPrompt = null;
    private $model = null;


    /**
     * Handles calls to methods that are not implemented by the provider.
     *
     * @param string $name Method name
     * @param array $arguments Method arguments
     * @throws NotImplementedException
     */
    public function __call( string $name, array $arguments )
    {
        throw new NotImplementedException( sprintf( '"%1$s" does not implement "%2$s"', get_class( $this), $name ) );
    }


    /**
     * Ensures that the provider has implemented the method.
     *
     * @param string $method Method name
     * @return Provider
     * @throws NotImplementedException
     */
    public function ensure( string $method ) : self
    {
        if( !$this->has( $name ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" does not implement "%2$s"', get_call( $this ), $method ) );
        }

        return $this;
    }


    /**
     * Tests if the provider has implemented the method.
     *
     * @param string $method Method name
     * @return bool TRUE if implemented, FALSE if absent
     */
    public function has( string $method ) : bool
    {
        $type = current( array_slice( explode( '\\', get_class( $this ) ), -2, 1 ) );
        $name = '\\Aimeos\\Prisma\\Contracts\\' . $type . '\\' . ucfirst( $method );

        if( !interface_exists( $name ) ) {
            return false;
        }

        if( !( $this instanceof $name ) ) {
            return false;
        }

        return true;
    }


    /**
     * Use the model passed by its name.
     *
     * Used if the provider supports more than one model and allows to select
     * between the different models. Otherwise, it's ignored.
     *
     * @param string|null $model Model name
     * @return self Provider interface
     */
    public function model( ?string $model ) : self
    {
        $this->model = $model;
        return $this;
    }


    /**
     * Add client handler for the Guzzle HTTP client.
     *
     * @param \GuzzleHttp\HandlerStack $stack List of Guzzle middleware
     * @return self Provider interface
     */
    public function withClientHandler( HandlerStack $stack ) : self
    {
        $this->clientHandler = $stack;
        return $this;
    }


    /**
     * Add options for the Guzzle HTTP client.
     *
     * @param array $options Associative list of name/value pairs
     * @return self Provider interface
     */
    public function withClientOptions( array $options ) : self
    {
        $this->clientOptions = array_replace_recursive( $this->clientOptions, $options );
        return $this;
    }


    /**
     * Add a system prompt for the LLM.
     *
     * It may be used by providers supporting system prompts. Otherwise, it's
     * ignored.
     *
     * @param string|null $prompt System prompt
     * @return self Provider interface
     */
    public function withSystemPrompt( ?string $prompt ) : self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }


    /**
     * Returns only the allowed options from the given list.
     *
     * @param array $options Associative list of name/value pairs
     * @param array $allowed List of allowed option names
     * @return array Filtered list of name/value pairs
     */
    protected function allowed( array $options, array $allowed ) : array
    {
        return array_intersect_key( $options, array_flip( $allowed ) );
    }


    /**
     * Set the base URL for the HTTP client.
     *
     * @param string|null $url Base URL
     * @return self Provider interface
     */
    protected function baseUrl( ?string $url ) : self
    {
        $this->clientOptions['base_uri'] = $url;
        return $this;
    }


    /**
     * Returns the HTTP client instance.
     *
     * @return Client HTTP client instance
     */
    protected function client() : Client
    {
        if( !isset( $this->client ) ) {
            $this->client = new Client( $this->clientOptions + ['http_errors' => false, 'handler' => $this->clientHandler] );
        }

        return $this->client;
    }


    /**
     * Set a header for the HTTP client.
     *
     * @param string $name Header name
     * @param string $value Header value
     * @return self Provider interface
     */
    protected function header( string $name, string $value ) : self
    {
        $this->clientOptions['headers'][$name] = $value;
        return $this;
    }


    /**
     * Returns the model name.
     *
     * @return string|null Model name
     */
    protected function modelName( string $default = null ) : ?string
    {
        return $this->model ?: $default;
    }


    /**
     * Returns the data for the HTTP request.
     *
     * @param array $options Associative list of name/value pairs
     * @param array $files Associative list of file name/File instances
     * @return array Request data
     */
    protected function request( array $options, array $files = [] ) : array
    {
        $data = [];

        foreach( $options as $key => $val ) {
            $data[] = ['name' => $key, 'contents' => $val];
        }

        foreach( $files as $name => $entry )
        {
            if( is_array( $entry ) )
            {
                foreach( $entry as $i => $file )
                {
                    if( !( $file instanceof File ) ) {
                        throw new BadRequestException( sprintf( 'Invalid file object for "%s"', $name ) );
                    }

                    $data[] = [
                        'name' => $name . "[$i]",
                        'contents' => $file->binary(),
                        'filename' => $file->filename() ?: "file-$i",
                        'headers'  => [
                            'Content-Type' => $file->mimeType()
                        ]
                    ];
                }
            }
            else
            {
                if( !( $entry instanceof File ) ) {
                    throw new BadRequestException( sprintf( 'Invalid file object for "%s"', $name ) );
                }

                $data[] = [
                    'name' => $name,
                    'contents' => $entry->binary(),
                    'filename' => $entry->filename() ?: 'file',
                    'headers'  => [
                        'Content-Type' => $entry->mimeType()
                    ]
                ];
            }
        }

        return !empty( $files ) ? ['multipart' => $data] : $data;
    }


    /**
     * Returns the system prompt.
     *
     * @return string|null System prompt
     */
    protected function systemPrompt() : ?string
    {
        return $this->systemPrompt;
    }
}
