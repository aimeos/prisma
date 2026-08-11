<?php

namespace Aimeos\Prisma\Files;

use Aimeos\Prisma\Concerns\FetchesUrls;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Psr7\Utils;


/**
 * The file content.
 */
class File
{
    use FetchesUrls;


    /** @var resource|null */
    protected mixed $stream = null;
    protected ?string $url = null;
    protected ?string $base64 = null;
    protected ?string $binary = null;
    protected ?string $filename = null;
    protected ?string $mimeType = null;
    protected int $maxBytes = 67108864;
    protected bool $strict = true;


    /**
     * Set the file name.
     *
     * @param string $name New file name
     * @return self File instance
     */
    public function as( string $name ) : self
    {
        $this->filename = $name;
        return $this;
    }


    /**
     * Returns the base64 encoded file content.
     *
     * @return string|null Base64 encoded content
     */
    public function base64() : ?string
    {
        if( $this->base64 === null ) {
            $this->base64 = base64_encode( (string) $this->binary() );
        }

        return $this->base64;
    }


    /**
     * Returns the binary file content.
     *
     * @return string|null Binary content
     */
    public function binary() : ?string
    {
        if( $this->binary !== null ) {
            return $this->binary;
        }

        if( $this->base64 !== null ) {
            return $this->binary = base64_decode( (string) $this->base64 );
        }

        if( $this->stream !== null )
        {
            $stream = self::resource( $this->stream );
            $content = stream_get_contents( $stream );

            if( $content === false ) {
                throw new PrismaException( 'Unable to read from stream' );
            }

            $this->stream = null;
            return $this->binary = $content;
        }

        if( $this->url && !( $this->binary = $this->fetch( $this->url, max( 0, $this->maxBytes ), $this->strict ) ?: null ) ) {
            throw new PrismaException( "Unable to fetch URL from {$this->url} or it is empty" );
        }

        return $this->binary;
    }


    /**
     * Returns the file name.
     *
     * @return string|null File name
     */
    public function filename() : ?string
    {
        return $this->filename;
    }


    /**
     * Create a file instance from a base64 encoded string.
     *
     * @param string $base64 Base64 encoded file content
     * @param string|null $mimeType Optional mime type
     * @return static File instance
     */
    public static function fromBase64( string $base64, ?string $mimeType = null ) : static
    {
        $instance = new static;
        $instance->base64 = $base64;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Create a file instance from binary content.
     *
     * @param string $binary Binary file content
     * @param string|null $mimeType Optional mime type
     * @return static File instance
     */
    public static function fromBinary( string $binary, ?string $mimeType = null ) : static
    {
        $instance = new static;
        $instance->binary = $binary;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Create a file instance from a local file path.
     *
     * @param string $path Local file path
     * @param string|null $mimeType Optional mime type
     * @return static File instance
     */
    public static function fromLocalPath( string $path, ?string $mimeType = null ) : static
    {
        // reject stream wrappers (php://, http://, ftp://, phar://, ...) so a local-path
        // argument cannot be turned into a remote fetch or a filter-based file disclosure
        if( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $path ) ) {
            throw new PrismaException( "Not a local file path: $path" );
        }

        if( !( $content = file_get_contents( $path ) ) ) {
            throw new PrismaException( "Unable to read file from $path or it is empty" );
        }

        $instance = new static;
        $instance->binary = $content;
        $instance->filename = basename( $path );
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Create a file instance from a Laravel storage path.
     *
     * @param string $path Storage file path
     * @param string|null $disk Optional storage disk name
     * @param string|null $mimeType Optional mime type
     * @return static File instance
     */
    public static function fromStoragePath( string $path, ?string $disk = null, ?string $mimeType = null ) : static
    {
        if( !class_exists( '\Illuminate\Support\Facades\Storage' ) ) {
            throw new NotImplementedException( 'Laravel storage facade is not available' );
        }

        $fsdisk = \Illuminate\Support\Facades\Storage::disk( $disk );

        /** @var string|null $content */
        $content = $fsdisk->get( $path );

        if( !$content ) {
            throw new NotFoundException( sprintf( 'Laravel storage disk "%1$s" does not contain "%2$s" or the file is empty', $disk, $path ) );
        }

        $instance = new static;
        $instance->binary = $content;
        $instance->filename = basename( $path );

        /** @var string|false $detectedMime */
        $detectedMime = $fsdisk->mimeType( $path );
        $instance->setMimeType( $mimeType ?: ( is_string( $detectedMime ) ? $detectedMime : null ) );

        return $instance;
    }


    /**
     * Create a file instance backed by a stream.
     *
     * @param resource $stream Readable stream resource
     * @param string|null $mimeType Optional mime type
     * @return static File instance
     */
    public static function fromStream( mixed $stream, ?string $mimeType = null ) : static
    {
        $instance = new static;
        $instance->stream = self::resource( $stream );
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Create a file instance from a URL.
     *
     * @param string $url File URL
     * @param string|null $mimeType Optional mime type
     * @param bool $strict TRUE to reject private and reserved addresses, FALSE to allow them
     * @return static File instance
     */
    public static function fromUrl( string $url, ?string $mimeType = null, bool $strict = true ) : static
    {
        $instance = new static;
        $instance->url = $url;
        $instance->strict = $strict;
        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Sets the maximum number of bytes fetched from a URL.
     *
     * @param int $bytes Maximum size in bytes
     * @return self File instance
     */
    public function maxSize( int $bytes ) : self
    {
        $this->maxBytes = $bytes;
        return $this;
    }


    /**
     * Returns the mime type.
     *
     * @return string|null Mime type
     */
    public function mimeType() : ?string
    {
        if( !$this->mimeType )
        {
            $mimeType = null;

            if( $this->binary !== null || $this->base64 !== null || $this->stream !== null )
            {
                $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer( (string) $this->binary() ) ?: null;
            }
            elseif( $this->url )
            {
                try
                {
                    // best-effort probe of the first bytes; an unsafe or unreachable URL stays null
                    $content = $this->fetch( $this->url, -255, $this->strict );
                    $mimeType = $content !== '' ? ( (new \finfo(FILEINFO_MIME_TYPE))->buffer( $content ) ?: null ) : null;
                }
                catch( PrismaException ) {}
            }

            $this->setMimeType( $mimeType );
        }

        if( !$this->acceptsMimeType( $this->mimeType ) ) {
            throw new PrismaException( sprintf( 'Invalid mime type "%2$s", expected %1$s*', $this->mimePrefix(), $this->mimeType ) );
        }

        return $this->mimeType;
    }


    /**
     * Sets the mime type.
     *
     * @param string|null $mimeType Mime type
     * @return static File instance
     */
    public function setMimeType( ?string $mimeType ) : static
    {
        // finfo reports several non-canonical WAV types; normalize them to "audio/wav" so
        // providers that only accept the canonical type (e.g. Gemini, Anthropic) work.
        $mimeType = match( $mimeType ) {
            'audio/x-wav', 'audio/wave', 'audio/x-pn-wav', 'audio/vnd.wave' => 'audio/wav',
            default => $mimeType,
        };

        if( $mimeType && !$this->acceptsMimeType( $mimeType ) ) {
            throw new PrismaException( sprintf( 'Invalid mime type "%2$s", expected %1$s*', $this->mimePrefix(), $mimeType ) );
        }

        $this->mimeType = $mimeType;
        return $this;
    }


    /**
     * Returns the file content as a stream.
     *
     * @return resource Retained input stream or a new readable stream
     */
    public function stream() : mixed
    {
        if( $this->stream !== null ) {
            return self::resource( $this->stream );
        }

        return self::resource( Utils::streamFor( (string) $this->binary() )->detach() );
    }


    /**
     * Returns the file URL.
     *
     * @return string|null File URL
     */
    public function url() : ?string
    {
        return $this->url;
    }


    final protected function __construct()
    {
    }


    /**
     * Returns the expected MIME type prefix for validation, or empty string if any type is allowed.
     *
     * @return string MIME prefix (e.g. 'image/', 'audio/', 'video/')
     */
    protected function mimePrefix() : string
    {
        return '';
    }


    /**
     * Tests whether a MIME type is valid for this file class.
     */
    protected function acceptsMimeType( ?string $mimeType ) : bool
    {
        return !( $prefix = $this->mimePrefix() ) || str_starts_with( (string) $mimeType, $prefix );
    }


    /**
     * Validates and returns a readable stream resource.
     *
     * @param mixed $stream Potential stream resource
     * @return resource Readable stream resource
     */
    private static function resource( mixed $stream ) : mixed
    {
        if( !is_resource( $stream ) || get_resource_type( $stream ) !== 'stream' ) {
            throw new PrismaException( 'Invalid stream resource' );
        }

        $mode = stream_get_meta_data( $stream )['mode'];

        if( !str_contains( $mode, 'r' ) && !str_contains( $mode, '+' ) ) {
            throw new PrismaException( 'Stream resource is not readable' );
        }

        return $stream;
    }
}
