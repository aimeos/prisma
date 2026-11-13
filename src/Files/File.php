<?php

namespace Aimeos\Prisma\Files;

use Aimeos\Prisma\Concerns\FetchesUrls;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Http\Network;
use GuzzleHttp\Psr7\Utils;


/**
 * The file content.
 */
class File
{
    use FetchesUrls;


    /** Bytes of seekable streams and URLs read for detecting the mime type, enough for the headers of media formats */
    private const HEAD_BYTES = 65536;

    /** @var resource|null */
    protected mixed $stream = null;
    protected ?string $url = null;
    protected ?string $base64 = null;
    protected ?string $binary = null;
    protected ?string $filename = null;
    protected ?string $mimeType = null;
    protected int $maxBytes = 67108864;

    /** Beginning of the content of a URL file, so it's fetched only once by head() */
    private ?string $prefix = null;


    /**
     * Reads a stream into memory before serialization because resources cannot be serialized.
     *
     * @return array<string, mixed> Object properties to serialize
     */
    public function __serialize() : array
    {
        if( $this->stream !== null ) {
            $this->binary();
        }

        // fetched again if needed because the file may have changed in the meantime
        return array_diff_key( get_object_vars( $this ), ['prefix' => true] );
    }


    /**
     * Restores the properties, including files serialized by versions which had a "strict" property.
     *
     * Private properties of this class are checked separately because property_exists() doesn't
     * find them for subclasses like Image.
     *
     * @param array<string, mixed> $data Serialized properties
     */
    public function __unserialize( array $data ) : void
    {
        foreach( $data as $key => $value )
        {
            // older versions serialized protected properties with their mangled names
            $name = ( $pos = strrpos( $key, "\0" ) ) !== false ? substr( $key, $pos + 1 ) : $key;

            if( $name === 'strict' ) {
                $this->fetchNetwork = ( new Network() )->withInternalHosts( !$value );
            } elseif( property_exists( self::class, $name ) || property_exists( $this, $name ) ) {
                $this->$name = $value;
            }
        }
    }


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

        if( $this->url && !( $this->binary = (string) $this->fetchBody( $this->url, $this->maxBytes ) ?: null ) ) {
            throw new PrismaException( "Unable to fetch URL from {$this->url} or it is empty" );
        }

        $this->prefix = null;
        return $this->binary;
    }


    /**
     * Returns the size of the file content without reading it.
     *
     * @return int|null Size in bytes or NULL if unknown, e.g. for URLs not fetched yet or pipes
     */
    public function bytes() : ?int
    {
        if( $this->binary !== null ) {
            return strlen( $this->binary );
        }

        if( $this->base64 !== null ) {
            // base64_decode() skips padding, line breaks and other characters outside the alphabet
            $length = strlen( $this->base64 ) - (int) preg_match_all( '#[^A-Za-z0-9+/]#', $this->base64 );
            return intdiv( $length * 3, 4 );
        }

        if( $this->stream !== null && stream_get_meta_data( $stream = self::resource( $this->stream ) )['seekable'] )
        {
            $size = fstat( $stream )['size'] ?? null;
            $pos = ftell( $stream );

            return is_int( $size ) && is_int( $pos ) ? max( 0, $size - $pos ) : null;
        }

        return null;
    }


    /**
     * Returns the file content in chunks to process large files with little memory.
     *
     * Content already in memory is sliced without copying it, streams and URLs are read chunk by chunk.
     * Seekable streams are set back to their position afterwards.
     *
     * @param int $bytes Maximum size of each chunk in bytes
     * @return \Generator<int, string> Non-empty chunks of the file content
     */
    public function chunks( int $bytes ) : \Generator
    {
        $bytes = max( 1, $bytes );

        if( $this->binary !== null || $this->base64 !== null || ( $this->stream === null && !$this->url ) )
        {
            $binary = (string) $this->binary();

            for( $pos = 0; $pos < strlen( $binary ); $pos += $bytes ) {
                yield substr( $binary, $pos, $bytes );
            }

            return;
        }

        $stream = $this->stream();
        $start = stream_get_meta_data( $stream )['seekable'] ? ftell( $stream ) : false;

        try
        {
            while( ( $chunk = stream_get_contents( $stream, $bytes ) ) !== false && $chunk !== '' ) {
                yield $chunk;
            }
        }
        finally
        {
            // seekable streams are rewound, so the file content can be used again
            if( $start !== false ) {
                fseek( $stream, $start );
            }
        }
    }


    /**
     * Returns the file content as base64 encoded data URI.
     *
     * @return string Data URI
     */
    public function dataUri() : string
    {
        return 'data:' . ( $this->mimeType() ?? 'application/octet-stream' ) . ';base64,' . $this->base64();
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
     * @param bool $strict FALSE to allow internal hosts and proxies, same as withInternalHosts()
     * @return static File instance
     */
    public static function fromUrl( string $url, ?string $mimeType = null, bool $strict = true ) : static
    {
        $instance = new static;
        $instance->url = $url;

        if( !$strict ) {
            $instance->withInternalHosts();
        }

        $instance->setMimeType( $mimeType );

        return $instance;
    }


    /**
     * Sets the maximum number of bytes fetched from a URL.
     *
     * @param int $bytes Maximum size in bytes or -1 for no limit
     * @return self File instance
     */
    public function maxSize( int $bytes ) : self
    {
        $this->maxBytes = $bytes < 0 ? PHP_INT_MAX : $bytes;
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
                $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer( $this->head() ) ?: null;
            }
            elseif( $this->url )
            {
                try
                {
                    // best-effort probe of the first bytes; an unsafe or unreachable URL stays null
                    $content = $this->head();
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
     * URL files not fetched yet are downloaded into a temporary stream on each call instead
     * of being kept in memory, so large files like videos can be stored at constant memory.
     *
     * @return resource Retained input stream or a new readable stream
     */
    public function stream() : mixed
    {
        if( $this->stream !== null ) {
            return self::resource( $this->stream );
        }

        if( $this->urlOnly() ) {
            return $this->fetchStream( (string) $this->url, $this->maxBytes );
        }

        return self::resource( Utils::streamFor( (string) $this->binary() )->detach() );
    }


    /**
     * Returns the public file URL or the file content as base64 encoded data URI.
     *
     * @return string File URL or data URI
     */
    public function uri() : string
    {
        return $this->url() ?: $this->dataUri();
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
     * Returns the beginning of the file content for detecting the mime type.
     *
     * Seekable streams are read partly and set back to their position, so large streams aren't
     * loaded into memory. The beginning of URL files is fetched by urlHead(). All other content is
     * read completely.
     *
     * @return string Beginning or complete file content
     * @throws PrismaException If the URL can't be fetched
     */
    protected function head() : string
    {
        if( $this->urlOnly() ) {
            return $this->urlHead();
        }

        if( $this->binary !== null || $this->base64 !== null || $this->stream === null
            || !stream_get_meta_data( $stream = self::resource( $this->stream ) )['seekable']
            || ( $pos = ftell( $stream ) ) === false
        ) {
            return (string) $this->binary();
        }

        $head = (string) stream_get_contents( $stream, self::HEAD_BYTES );
        fseek( $stream, $pos );

        return $head;
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


    /**
     * Tests if the file content is only available from its URL.
     *
     * @return bool TRUE if the file has a URL but no content or stream yet
     */
    private function urlOnly() : bool
    {
        return $this->url && $this->binary === null && $this->base64 === null && $this->stream === null;
    }


    /**
     * Returns the beginning of a URL file, fetched once.
     *
     * The beginning is kept for the mime type and image size. Files smaller than that are kept as
     * complete content, so binary() doesn't fetch them again.
     *
     * @return string Beginning or complete file content
     * @throws PrismaException If the URL can't be fetched
     */
    private function urlHead() : string
    {
        if( $this->prefix === null )
        {
            $this->prefix = (string) $this->fetchBody( (string) $this->url, -self::HEAD_BYTES );

            if( $this->prefix !== '' && strlen( $this->prefix ) < self::HEAD_BYTES && strlen( $this->prefix ) <= $this->maxBytes ) {
                [$this->binary, $this->prefix] = [$this->prefix, null];
                return $this->binary;
            }
        }

        return $this->prefix;
    }
}
