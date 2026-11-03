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
     * Builds the response files from the result of a completed request.
     *
     * @param array<string, mixed> $data Result data of the model endpoint
     * @return array<int, File> Response files
     */
    abstract protected function falFiles( array $data ) : array;


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
            $result->withMeta( array_diff_key( $data, ['image' => null, 'images' => null] ) );

            foreach( $this->falFiles( $data ) as $file ) {
                $result->add( $file );
            }

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
