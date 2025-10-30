<?php

namespace Aimeos\Prisma\Files;


class File
{
    protected ?string $url = null;
    protected ?string $base64 = null;
    protected ?string $binary = null;
    protected ?string $localpath = null;
    protected ?string $filename = null;


    private function __construct()
    {
    }


    public static function fromBase64( string $base64, ?string $mimeType = null ) : static
    {
        $instance = new static;

        $instance->base64 = $base64;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    public static function fromBinary( string $binary, ?string $mimeType = null ) : static
    {
        $instance = new static;

        $instance->binary = $binary;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    public static function fromLocalPath( string $path, ?string $mimeType = null ) : static
    {
        if( !( $content = file_get_contents( $path ) ) ) {
            throw new InvalidArgumentException( "Unable to read file from $path or it is empty" );
        }

        $instance = new static;

        $instance->binary = $content;
        $instance->filename = basename( $path );
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    public static function fromUrl( string $url, ?string $mimeType = null ) : static
    {
        $instance = new static;

        $instance->url = $url;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    public function as( string $name ) : self
    {
        $this->filename = $name;
        return $this;
    }


    public function base64() : ?string
    {
        if( !$this->base64 ) {
            $this->base64 = base64_encode( (string) $this->binary() );
        }

        return $this->base64;
    }


    public function binary() : ?string
    {
        if( $this->binary ) {
            return $this->binary;
        }

        if( $this->base64 ) {
            return $this->binary = base64_decode( (string) $this->base64 );
        }

        if( $this->url && !( $this->binary = file_get_contents( $this->url ) ?: null ) ) {
            throw new InvalidArgumentException( "Unable to fetch URL from {$this->url} or it is empty" );
        }

        return $this->binary;
    }


    public function filename() : ?string
    {
        return $this->filename;
    }


    public function mimeType() : ?string
    {
        if( !$this->mimeType ) {
            $this->mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer( $this->binary() ) ?: null;
        }

        return $this->mimeType;
    }


    public function setMimeType( ?string $mimeType ) : self
    {
        $this->mimeType = $mimeType;
        return $this;
    }


    public function url() : ?string
    {
        return $this->url;
    }
}