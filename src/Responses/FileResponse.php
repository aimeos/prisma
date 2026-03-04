<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\Async;
use Aimeos\Prisma\Concerns\HasDescription;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;
use Aimeos\Prisma\Files\File;


/**
 * File based response.
 *
 * @implements \IteratorAggregate<int|string, File>
 */
class FileResponse implements \IteratorAggregate
{
    use Async, HasDescription, HasMeta, HasUsage;


    /** @var array<string|int, File> */
    protected array $list = [];


    final private function __construct()
    {
    }


    /**
     * Create a file instance from a base64 encoded string.
     *
     * @param string $base64 Base64 encoded file content
     * @param string|null $mimeType Optional mime type
     * @return static FileResponse instance
     */
    public static function fromBase64( string $base64, ?string $mimeType = null ) : static
    {
        return (new static)->add( File::fromBase64( $base64, $mimeType ) );
    }


    /**
     * Create a file instance from binary content.
     *
     * @param string $binary Binary file content
     * @param string|null $mimeType Optional mime type
     * @return static FileResponse instance
     */
    public static function fromBinary( string $binary, ?string $mimeType = null ) : static
    {
        return (new static)->add( File::fromBinary( $binary, $mimeType ) );
    }


    /**
     * Create file instances from an array of file objects.
     *
     * @param array<int|string, File> $files List of File objects
     * @return static FileResponse instance
     */
    public static function fromFiles( array $files ) : static
    {
        $instance = new static();

        foreach( $files as $key => $file ) {
            $instance->add( $file, $key );
        }

        return $instance;
    }


    /**
     * Create a file instance from a local file path.
     *
     * @param string $path Local file path
     * @param string|null $mimeType Optional mime type
     * @return static FileResponse instance
     */
    public static function fromLocalPath( string $path, ?string $mimeType = null ) : static
    {
        return (new static)->add( File::fromLocalPath( $path, $mimeType ) );
    }


    /**
     * Create a file instance from a Laravel storage path.
     *
     * @param string $path Storage file path
     * @param string|null $disk Optional storage disk name
     * @param string|null $mimeType Optional mime type
     * @return static FileResponse instance
     */
    public static function fromStoragePath( string $path, ?string $disk = null, ?string $mimeType = null ) : static
    {
        return (new static)->add( File::fromStoragePath( $path, $disk, $mimeType ) );
    }


    /**
     * Create a file instance from a URL.
     *
     * @param string $url File URL
     * @param string|null $mimeType Optional mime type
     * @return static FileResponse instance
     */
    public static function fromUrl( string $url, ?string $mimeType = null ) : static
    {
        return (new static)->add( File::fromUrl( $url, $mimeType ) );
    }


    /**
     * Add a file object to the list of files if several are available.
     *
     * @param File $file File object to add
     * @param int|string|null $key Optional key to associate with the file
     * @return static FileResponse instance
     */
    public function add( File $file, int|string|null $key = null ) : static
    {
        if( $key !== null ) {
            $this->list[$key] = $file;
        } else {
            $this->list[] = $file;
        }

        return $this;
    }


    /**
     * Set the file name.
     *
     * @param string $name New file name
     * @return static FileResponse instance
     */
    public function as( string $name ) : static
    {
        $this->first()?->as( $name );
        return $this;
    }


    /**
     * Returns the base64 encoded file content.
     *
     * @return string|null Base64 encoded content
     */
    public function base64() : ?string
    {
        return $this->first()?->base64();

    }


    /**
     * Returns the binary content, waiting if necessary.
     *
     * @return string|null Binary content
     */
    public function binary() : ?string
    {
        return $this->first()?->binary();
    }


    /**
     * Checks if there are any results.
     *
     * @return bool True if there are no results, false otherwise
     */
    public function empty() : bool
    {
        return empty( $this->list );
    }


    /**
     * Returns the file name.
     *
     * @return string|null File name
     */
    public function filename() : ?string
    {
        return $this->first()?->filename();
    }


    /**
     * Get all available files.
     *
     * @return array<int|string, File> List of files
     */
    public function files() : array
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return $this->list;
    }


    /**
     * Returns the first file in the list if several are available.
     *
     * @return File|null First file object or null if no files are available
     */
    public function first() : ?File
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return reset( $this->list ) ?: null;
    }


    /**
     * Allows iterating over the list of available files.
     *
     * @return \ArrayIterator<int|string, File> Traversable list of File objects
     */
    public function getIterator(): \Traversable
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return new \ArrayIterator( $this->list );
    }


    /**
     * Returns the mime type.
     *
     * @return string|null Mime type
     */
    public function mimeType() : ?string
    {
        return $this->first()?->mimeType();
    }


    /**
     * Returns the file URL.
     *
     * @return string|null File URL
     */
    public function url() : ?string
    {
        return $this->first()?->url();
    }
}
