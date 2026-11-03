<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\FileResponse;
use GuzzleHttp\Psr7\Uri;


/**
 * Queue API of fal.ai shared by all fal model endpoints.
 *
 * The job ID is the "response_url" returned by fal when submitting a request. Status, result
 * and cancel URLs are derived from it because they contain the owner and app of the model only,
 * not the full endpoint path the request has been submitted to.
 */
trait FalApi
{
    /**
     * Builds a response file from an output file URL of fal.
     *
     * @param string $url File URL returned by fal
     * @param string|null $mimeType Content type of the file if known
     * @return File Response file
     */
    abstract protected function falFile( string $url, ?string $mimeType ) : File;


    /**
     * Cancels a queued or running request.
     *
     * @param string $jobId Response URL returned by jobId()
     * @return void
     * @throws BadRequestException If the URL isn't a request URL of the fal queue API
     */
    public function cancel( string $jobId ) : void
    {
        $uri = $this->falUrl( $jobId );
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
        return FileResponse::fromAsync( $this->falPoll( $this->falUrl( $jobId ) ), 3, jobId: $jobId );
    }


    /**
     * Adds the output files of a completed request to the response.
     *
     * fal returns file objects with an "url" property, either as single values whose output
     * name becomes the file key (e.g. "audio" or the "vocals" stem) or as lists (e.g. "images").
     *
     * @param array<string, mixed> $data Result data of the model endpoint
     * @param FileResponse $result File response to add the files to
     * @return array<int, string> Output names of the added files
     */
    protected function falFiles( array $data, FileResponse $result ) : array
    {
        $keys = [];

        foreach( $data as $key => $value )
        {
            if( $this->falObject( $value ) ) {
                $result->add( $this->falFile( $value['url'], $this->falType( $value ) ), $key );
                $keys[] = $key;
            } elseif( $this->falList( $value ) ) {
                foreach( $value as $item ) {
                    $result->add( $this->falFile( $item['url'], $this->falType( $item ) ) );
                }
                $keys[] = $key;
            }
        }

        return $keys;
    }


    /**
     * Tests if the value is a non-empty list of fal file objects.
     *
     * @param mixed $value Output value
     * @return bool TRUE if all list items are file objects
     * @phpstan-assert-if-true non-empty-list<array{url: non-empty-string}> $value
     */
    protected function falList( mixed $value ) : bool
    {
        return is_array( $value ) && $value !== [] && array_is_list( $value )
            && array_filter( $value, $this->falObject(...) ) === $value;
    }


    /**
     * Returns the public URL or the base64 data URI of the file.
     *
     * @param File $file Input file
     * @return string URL or data URI
     */
    protected function falMedia( File $file ) : string
    {
        return $file->url() ?: 'data:' . ( $file->mimeType() ?? 'application/octet-stream' )
            . ';base64,' . $file->base64();
    }


    /**
     * Tests if the value is a fal file object.
     *
     * @param mixed $value Output value
     * @return bool TRUE if the value is an array with a non-empty URL
     * @phpstan-assert-if-true array{url: non-empty-string} $value
     */
    protected function falObject( mixed $value ) : bool
    {
        return is_array( $value ) && is_string( $value['url'] ?? null ) && $value['url'] !== '';
    }


    /**
     * Returns a closure that polls the status of a request and fetches its result.
     *
     * @param Uri $uri Validated response URL of the request
     * @return \Closure Polling closure that populates the file response
     */
    protected function falPoll( Uri $uri ) : \Closure
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

            $response = $this->client()->get( $uri, ['allow_redirects' => false] );
            $this->validate( $response );

            $data = $this->fromJson( $response );
            $files = $this->falFiles( $data, $result );
            $result->withMeta( array_diff_key( $data, array_flip( $files ) ) );

            if( $result->empty() ) {
                throw new FailedException( 'No files found in fal response' );
            }

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
    protected function falQueue( string $endpoint, array $input ) : FileResponse
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
     * Returns the content type of a fal file object.
     *
     * @param array<string, mixed> $value File object
     * @return string|null Content type or NULL if unknown
     */
    protected function falType( array $value ) : ?string
    {
        return is_string( $value['content_type'] ?? null ) && $value['content_type'] !== '' ? $value['content_type'] : null;
    }


    /**
     * Validates a response URL of the fal queue API.
     *
     * @param string $url Response URL returned by fal or passed as job ID
     * @return Uri Validated response URL
     * @throws BadRequestException If the URL isn't a request URL of the fal queue API
     */
    protected function falUrl( string $url ) : Uri
    {
        // fal returns response URLs of its queue host even if the requests are sent through a gateway
        return $this->jobUrl( $url, '#/requests/[\w-]+$#D', '#^queue\.fal\.run$#D', 'Invalid fal response URL' );
    }
}
