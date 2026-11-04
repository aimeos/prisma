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
use GuzzleHttp\Psr7\Uri;


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

    /** Default LLM of the OpenRouter endpoints */
    private const ROUTER_MODEL = 'google/gemini-3.8-flash';

    /** @var array<int, string> */
    private array $downloadHosts;
    private string $runUrl;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $hosts = $config['download_hosts'] ?? null;
        $this->downloadHosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : ['fal.media'];

        $this->runUrl = rtrim( $this->config( $config, 'run_url', 'https://fal.run' ), '/' ) . '/';

        $this->header( 'Authorization', 'Key ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://queue.fal.run' ) );
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
        return FileResponse::fromAsync( $this->poll( $this->requestUrl( $jobId ) ), 3, jobId: $jobId );
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
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( Uri $uri ) : \Closure
    {
        return function( FileResponse $result ) use ( $uri ) : bool {
            $response = $this->client()->get( $uri->withPath( $uri->getPath() . '/status' ), ['allow_redirects' => false] );
            $this->validate( $response );

            $data = $this->fromJson( $response );
            $result->withMeta( $data );

            if( ( $data['status'] ?? null ) !== 'COMPLETED' ) {
                return false;
            }

            if( !empty( $data['error'] ) ) {
                throw new FailedException( is_string( $data['error'] ) ? $data['error'] : 'fal request failed' );
            }

            $this->result( $uri, $result );
            return true;
        };
    }


    /**
     * Submits a request to the queue of a model endpoint.
     *
     * @param string $endpoint Model endpoint ID, e.g. "fal-ai/flux-2"
     * @param array<string, mixed> $input Model input
     * @return FileResponse Asynchronous file response
     */
    protected function queue( string $endpoint, array $input ) : FileResponse
    {
        $response = $this->client()->post( $endpoint, ['json' => $input] );
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $url = $data['response_url'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            throw new FailedException( 'No response URL in fal response' );
        }

        return $this->resume( $url );
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
     * Fetches the result of a completed request and adds its files and meta data to the response.
     *
     * @param Uri $uri Validated response URL of the request
     * @param FileResponse $result File response to populate
     * @return void
     * @throws FailedException If the result contains no files
     */
    protected function result( Uri $uri, FileResponse $result ) : void
    {
        $response = $this->client()->get( $uri, ['allow_redirects' => false] );
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $this->files( $data, $result );
        $result->withMeta( array_filter( $data, fn( $value ) => !$this->isFile( $value ) && !$this->isFileList( $value ) ) );

        if( $result->empty() ) {
            throw new FailedException( 'No files found in fal response' );
        }
    }


    /**
     * Asks an LLM about the media files using a synchronous OpenRouter endpoint of fal.
     *
     * @param string $endpoint OpenRouter endpoint of fal, e.g. "openrouter/router/vision"
     * @param string $prompt Instructions for the LLM
     * @param array<string, mixed> $input Media fields of the endpoint
     * @param array<string, mixed> $options Provider specific options, "model" selects the LLM
     * @return TextResponse Response text
     */
    protected function router( string $endpoint, string $prompt, array $input, array $options ) : TextResponse
    {
        // model() selects the queue endpoint, so the LLM is passed as option
        $data = $this->run( $endpoint, array_replace( ['model' => self::ROUTER_MODEL], $options, $input, [
            'prompt' => $prompt,
        ] ) );

        if( !is_string( $data['output'] ?? null ) ) {
            throw new PrismaException( 'No text found in fal response' );
        }

        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
        $used = $usage['total_tokens'] ?? null;

        return TextResponse::fromText( $data['output'] )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
            ->withMeta( array_diff_key( $data, ['output' => 1, 'usage' => 1] ) );
    }


    /**
     * Sends a synchronous request to a model endpoint and returns its result.
     *
     * Used for text results only because resume() can continue file responses only.
     *
     * @param string $endpoint Model endpoint ID, e.g. "fal-ai/elevenlabs/speech-to-text/scribe-v2"
     * @param array<string, mixed> $input Model input
     * @return array<string, mixed> Result data of the model endpoint
     */
    protected function run( string $endpoint, array $input ) : array
    {
        $response = $this->client()->post( $this->runUrl . $endpoint, ['json' => $input] );
        $this->validate( $response );

        return $this->fromJson( $response );
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
        return $this->queue( $this->modelName( $default ), array_replace( $options, $input ) );
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
}
