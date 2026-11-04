<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;


/**
 * Queue API of fal.ai shared by all fal model endpoints.
 *
 * The job ID is the "response_url" returned by fal when submitting a request. Status, result
 * and cancel URLs are derived from it because they contain the owner and app of the model only,
 * not the full endpoint path the request has been submitted to.
 */
abstract class Fal extends Base implements Cancel, Resume
{
    /** @var class-string<File> File class of the output files */
    protected const FILE = File::class;

    /** Maximum size of the downloaded output files in bytes */
    protected const MAX_BYTES = 64 * 1024 * 1024;

    /** Polling interval of the queued requests in seconds */
    protected const RETRY = 3;

    /** Polling interval of the queued text requests in seconds, LLM answers often take only a few seconds */
    private const ASK_RETRY = 1;

    /** Files up to this size are sent inline as data URI because uploading them takes three requests */
    private const INLINE_BYTES = 5 * 1024 * 1024;

    /** Size of the uploaded parts, so each request of large uploads stays short */
    private const PART_BYTES = 10 * 1024 * 1024;

    /** Number of parts uploaded at the same time */
    private const PART_UPLOADS = 4;

    /** Default LLM of the OpenRouter endpoints */
    private const ROUTER_MODEL = 'google/gemini-3.8-flash';

    /** @var array<int, string> */
    private array $downloadHosts;
    private string $restUrl;

    /** @var \WeakMap<File, string>|null URLs of the uploaded files, so files used several times are uploaded once */
    private ?\WeakMap $uploads = null;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $hosts = $config['download_hosts'] ?? null;
        $this->downloadHosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : ['fal.media'];

        $this->restUrl = rtrim( $this->config( $config, 'rest_url', 'https://rest.fal.ai' ), '/' ) . '/';

        $this->header( 'Authorization', 'Key ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://queue.fal.run' ) );
        $this->pollTimeout( $config );
    }


    /**
     * Cancels a queued or running request.
     *
     * @param string $jobId Response URL returned by jobId()
     * @return void
     * @throws BadRequestException If the URL isn't a request URL of the fal queue API
     */
    public function cancel( string $jobId ) : void
    {
        $uri = $this->requestUrl( $jobId );
        $response = $this->client()->put( $uri->withPath( $uri->getPath() . '/cancel' ), ['allow_redirects' => false] );

        $this->validate( $response );
    }


    /**
     * Continues polling a queued or running request.
     *
     * @param string $jobId Response URL returned by jobId()
     * @return FileResponse Asynchronous file response
     * @throws BadRequestException If the URL isn't a request URL of the fal queue API
     */
    public function resume( string $jobId ) : FileResponse
    {
        $poll = $this->poll( $this->requestUrl( $jobId ), $this->result(...) );
        return FileResponse::fromAsync( $poll, static::RETRY, $this->pollTimeout, $jobId );
    }


    /**
     * Submits a request returning text to the queue of a model endpoint.
     *
     * Text responses can't be resumed because resume() returns file responses only.
     *
     * @param string $endpoint Model endpoint ID, e.g. "fal-ai/elevenlabs/speech-to-text/scribe-v2"
     * @param array<string, mixed> $input Model input
     * @param \Closure $result Closure that populates the text response from the result data
     * @return TextResponse Asynchronous text response
     */
    protected function ask( string $endpoint, array $input, \Closure $result ) : TextResponse
    {
        // text results are fast, so they are polled more often than generated files
        return TextResponse::fromAsync( $this->poll( $this->queue( $endpoint, $input ), $result ), self::ASK_RETRY, $this->pollTimeout );
    }


    /**
     * Builds a lazy file whose eventual download stays on the fal CDN.
     *
     * @param string $url File URL returned by fal
     * @param string|null $mimeType Content type of the file if known
     * @return File Response file
     */
    protected function file( string $url, ?string $mimeType ) : File
    {
        $class = static::FILE;

        $file = $class::fromUrl( $url, $mimeType );
        $file->restrictHosts( $this->downloadHosts );
        $file->maxSize( static::MAX_BYTES );

        return $file;
    }


    /**
     * Adds the output files of a completed request to the response.
     *
     * fal returns file objects with an "url" property, either as single values whose output
     * name becomes the file key (e.g. "audio" or the "vocals" stem) or as lists (e.g. "images").
     *
     * @param array<string, mixed> $data Result data of the model endpoint
     * @param FileResponse $result File response to add the files to
     * @return void
     */
    protected function files( array $data, FileResponse $result ) : void
    {
        foreach( $data as $key => $value )
        {
            if( $this->isFile( $value ) ) {
                $result->add( $this->file( $value['url'], $this->mimeType( $value ) ), $key );
            } elseif( $this->isFileList( $value ) ) {
                foreach( $value as $item ) {
                    $result->add( $this->file( $item['url'], $this->mimeType( $item ) ) );
                }
            }
        }
    }


    /**
     * Tests if the value is a fal file object.
     *
     * @param mixed $value Output value
     * @return bool TRUE if the value is an array with a non-empty URL
     * @phpstan-assert-if-true array{url: non-empty-string} $value
     */
    protected function isFile( mixed $value ) : bool
    {
        return is_array( $value ) && is_string( $value['url'] ?? null ) && $value['url'] !== '';
    }


    /**
     * Tests if the value is a non-empty list of fal file objects.
     *
     * @param mixed $value Output value
     * @return bool TRUE if all list items are file objects
     * @phpstan-assert-if-true non-empty-list<array{url: non-empty-string}> $value
     */
    protected function isFileList( mixed $value ) : bool
    {
        return is_array( $value ) && $value !== [] && array_is_list( $value )
            && array_filter( $value, $this->isFile(...) ) === $value;
    }


    /**
     * Returns the content type of a fal file object.
     *
     * @param array<string, mixed> $value File object
     * @return string|null Content type or NULL if unknown
     */
    protected function mimeType( array $value ) : ?string
    {
        return is_string( $value['content_type'] ?? null ) && $value['content_type'] !== '' ? $value['content_type'] : null;
    }


    /**
     * Returns a closure that polls the status of a request until it is completed.
     *
     * @param Uri $uri Validated response URL of the request
     * @param \Closure $result Closure that populates the response from the result data
     * @return \Closure Polling closure that populates the response
     */
    protected function poll( Uri $uri, \Closure $result ) : \Closure
    {
        return function( FileResponse|TextResponse $response ) use ( $uri, $result ) : bool {
            $status = $this->client()->get( $uri->withPath( $uri->getPath() . '/status' ), ['allow_redirects' => false] );
            $this->validate( $status );

            $data = $this->fromJson( $status );
            $response->withMeta( $data );

            if( ( $data['status'] ?? null ) !== 'COMPLETED' ) {
                return false;
            }

            if( !empty( $data['error'] ) ) {
                throw new FailedException( is_string( $data['error'] ) ? $data['error'] : 'fal request failed' );
            }

            $output = $this->client()->get( $uri, ['allow_redirects' => false] );
            $this->validate( $output );

            $result( $this->fromJson( $output ), $response );
            return true;
        };
    }


    /**
     * Submits a request to the queue of a model endpoint.
     *
     * @param string $endpoint Model endpoint ID, e.g. "fal-ai/flux-2"
     * @param array<string, mixed> $input Model input
     * @return Uri Validated response URL of the request
     */
    protected function queue( string $endpoint, array $input ) : Uri
    {
        $response = $this->client()->post( $endpoint, ['json' => $input] );
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $url = $data['response_url'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            throw new FailedException( 'No response URL in fal response' );
        }

        return $this->requestUrl( $url );
    }


    /**
     * Validates a response URL of the fal queue API.
     *
     * @param string $url Response URL returned by fal or passed as job ID
     * @return Uri Validated response URL
     * @throws BadRequestException If the URL isn't a request URL of the fal queue API
     */
    protected function requestUrl( string $url ) : Uri
    {
        // fal returns response URLs of its queue host even if the requests are sent through a gateway
        return $this->jobUrl( $url, '#/requests/[\w-]+$#D', '#^queue\.fal\.run$#D', 'Invalid fal response URL' );
    }


    /**
     * Adds the files and meta data of a completed request to the response.
     *
     * @param array<string, mixed> $data Result data of the model endpoint
     * @param FileResponse $result File response to populate
     * @return void
     * @throws FailedException If the result contains no files
     */
    protected function result( array $data, FileResponse $result ) : void
    {
        $this->files( $data, $result );
        $result->withMeta( array_filter( $data, fn( $value ) => !$this->isFile( $value ) && !$this->isFileList( $value ) ) );

        if( $result->empty() ) {
            throw new FailedException( 'No files found in fal response' );
        }
    }


    /**
     * Asks an LLM about the media files using an OpenRouter endpoint of fal.
     *
     * @param string $endpoint OpenRouter endpoint of fal, e.g. "openrouter/router/vision"
     * @param string $prompt Instructions for the LLM
     * @param array<string, mixed> $input Media fields of the endpoint
     * @param array<string, mixed> $options Provider specific options, "model" selects the LLM
     * @return TextResponse Asynchronous response text
     */
    protected function router( string $endpoint, string $prompt, array $input, array $options ) : TextResponse
    {
        // model() selects the queue endpoint, so the LLM is passed as option
        $input = array_replace( ['model' => self::ROUTER_MODEL], $options, $input, ['prompt' => $prompt] );

        return $this->ask( $endpoint, $input, function( array $data, TextResponse $response ) {

            if( !is_string( $data['output'] ?? null ) ) {
                throw new FailedException( 'No text found in fal response' );
            }

            $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
            $used = $usage['total_tokens'] ?? null;

            $response->add( $data['output'] )
                ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
                ->withMeta( array_diff_key( $data, ['output' => 1, 'usage' => 1] ) );
        } );
    }


    /**
     * Submits a request to the queue of the configured or default model endpoint.
     *
     * @param string $default Default model endpoint ID if no model is configured
     * @param array<string, mixed> $input Model input overwriting the provider specific options
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Asynchronous file response
     */
    protected function submit( string $default, array $input, array $options ) : FileResponse
    {
        return $this->resume( (string) $this->queue( $this->modelName( $default ), array_replace( $options, $input ) ) );
    }


    /**
     * Returns the upscaling fields of the fal upscale endpoints.
     *
     * @param int $factor Upscale factor, limited to 1 to 10 by fal
     * @return array<string, mixed> Upscaling fields
     */
    protected function upscaling( int $factor ) : array
    {
        return ['upscale_mode' => 'factor', 'upscale_factor' => max( 1, min( 10, $factor ) )];
    }


    /**
     * Returns the URL of the file or uploads it to the fal storage if it's large.
     *
     * Large files are uploaded because data URIs need several times the file size as
     * memory when sent as JSON and slow down the requests to the model endpoints.
     *
     * @param File $file Input file
     * @return string File URL or data URI
     */
    protected function uri( File $file ) : string
    {
        if( $url = $file->url() ) {
            return $url;
        }

        // files of unknown size, e.g. pipes, are uploaded because they may be large
        if( ( $size = $file->bytes() ) !== null && $size <= self::INLINE_BYTES ) {
            return $file->dataUri();
        }

        $this->uploads ??= new \WeakMap();
        return $this->uploads[$file] ??= $this->upload( $file );
    }


    /**
     * Returns the ETag of an uploaded part.
     *
     * @param ResponseInterface $response Validated upload response of the part
     * @return string ETag of the part
     * @throws FailedException If the response contains no ETag
     */
    private function etag( ResponseInterface $response ) : string
    {
        $data = json_decode( (string) $response->getBody(), true );
        $etag = is_array( $data ) && is_string( $data['etag'] ?? null ) ? $data['etag'] : $response->getHeaderLine( 'ETag' );

        if( $etag === '' ) {
            throw new FailedException( 'No ETag in fal upload response' );
        }

        return $etag;
    }


    /**
     * Uploads the file content in parts to the URL returned by fal.
     *
     * Several parts are sent at the same time and only those are kept in memory.
     *
     * @param Uri $uri Signed upload URL of the multipart upload
     * @param File $file Input file
     * @return void
     */
    private function parts( Uri $uri, File $file ) : void
    {
        $etags = [];

        $requests = function() use ( $uri, $file ) {
            foreach( $file->chunks( self::PART_BYTES ) as $idx => $chunk ) {
                yield $idx => new Request( 'PUT', $uri->withPath( $uri->getPath() . '/' . ( $idx + 1 ) ), [], $chunk );
            }
        };

        // the signed upload URL authorizes the requests, so the API key isn't sent
        ( new Pool( $this->client(), $requests(), [
            'concurrency' => self::PART_UPLOADS,
            'options' => ['headers' => null],
            'fulfilled' => function( ResponseInterface $response, int $idx ) use ( &$etags ) {
                $this->validate( $response );
                $etags[$idx] = $this->etag( $response );
            },
            'rejected' => fn( mixed $reason ) => throw ( $reason instanceof \Throwable ? $reason : new FailedException( 'fal upload failed' ) ),
        ] ) )->promise()->wait();

        ksort( $etags );
        $parts = array_map( fn( $idx, $etag ) => ['partNumber' => $idx + 1, 'etag' => $etag], array_keys( $etags ), $etags );

        $response = $this->client()->post( $uri->withPath( $uri->getPath() . '/complete' ), [
            'json' => ['parts' => $parts],
            'headers' => null,
        ] );

        $this->validate( $response );
    }


    /**
     * Uploads the file content to the fal storage.
     *
     * @param File $file Input file
     * @return string URL of the uploaded file
     */
    private function upload( File $file ) : string
    {
        $url = $this->restUrl . 'storage/upload/initiate-multipart?storage_type=fal-cdn-v3';
        $response = $this->client()->post( $url, ['json' => [
            'content_type' => $file->mimeType() ?? 'application/octet-stream',
            'file_name' => $file->filename() ?? 'upload',
        ]] );

        $this->validate( $response );
        $data = $this->fromJson( $response );

        if( !str_starts_with( (string) ( $data['upload_url'] ?? '' ), 'https://' ) || !is_string( $data['file_url'] ?? null ) ) {
            throw new FailedException( 'No upload URL in fal response' );
        }

        $this->parts( new Uri( $data['upload_url'] ), $file );
        return $data['file_url'];
    }
}
