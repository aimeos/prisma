<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use GuzzleHttp\Client;


abstract class Base implements Provider
{
    private $client;
    private $clientOptions = [];
    private $sysPrompt = null;
    private $model = null;


    public function __call( string $name, array $arguments )
    {
        throw new NotImplementedException( sprintf( '"%1$s" does not implement "%2$s"', get_class( $this), $name ) );
    }


    public function ensure( string $method ) : self
    {
        if( !$this->has( $name ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" does not implement "%2$s"', get_call( $this ), $method ) );
        }

        return $this;
    }


    public function has( string $method ) : bool
    {
        $name = '\\Aimeos\\Prisma\\Contracts\\' . ucfirst( $method );

        if( !interface_exists( $name ) ) {
            return false;
        }

        if( !( $this instanceof $name ) ) {
            return false;
        }

        return true;
    }


    public function model( ?string $model ) : self
    {
        $this->model = $model;
        return $this;
    }


    public function options( array $options ) : self
    {
        $this->clientOptions = array_replace_recursive( $clientOptions, $options );
        return $this;
    }


    public function systemPrompt( ?string $prompt ) : self
    {
        $this->sysPrompt = $prompt;
        return $this;
    }


    protected function baseUrl( ?string $url ) : self
    {
        $this->clientOptions['base_uri'] = $url;
        return $this;
    }


    protected function client() : Client
    {
        if( !isset( $this->client ) ) {
            $this->client = new Client( $this->clientOptions );
        }

        return $this->client;
    }


    protected function data( array $options, array $files = [] ) : array
    {
        $data = [];

        foreach( $options as $key => $val ) {
            $data[] = ['name' => $key, 'contents' => $val];
        }

        foreach( $files as $name => $file )
        {
            $data[] = [
                'name' => $name,
                'contents' => $file->binary(),
                'filename' => $file->filename() ?: 'file',
                'headers'  => [
                    'Content-Type' => $file->mimeType()
                ]
            ];
        }

        return !empty( $files ) ? $data : ['multipart' => $data];
    }


    protected function header( string $name, string $value ) : self
    {
        $this->clientOptions['headers'][$name] = $value;
        return $this;
    }


    protected function modelName() : ?string
    {
        return $this->model;
    }


    protected function sysPrompt() : ?string
    {
        return $this->sysPrompt;
    }
}