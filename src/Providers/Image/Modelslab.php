<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Modelslab extends Base implements Imagine, Resume
{
    private const FETCH = '#/api/v\d+(/[\w-]+)*/fetch/\w[\w.-]*$#D';

    /** HTTP status codes of the request errors ModelsLab reports in responses with HTTP status 200 */
    private const ERRORS = [
        'validation_error' => 400,
        'invalid_api_key' => 401, 'unauthenticated' => 401,
        'insufficient_balance' => 402, 'no_subscription' => 402,
        'forbidden' => 403,
        'not_found' => 404,
        'rate_limited' => 429,
        'upstream_unavailable' => 503,
    ];

    private string $apiKey;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->apiKey = $this->config( $config, 'api_key' );
        $this->baseUrl( $this->config( $config, 'url', 'https://modelslab.com' ) );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $request = [
            'key' => $this->apiKey,
            'model_id' => $this->modelName( 'flux' ),
            'prompt' => $prompt,
        ] + $this->allowed( $options, [
            'negative_prompt', 'width', 'height', 'samples', 'num_inference_steps',
            'guidance_scale', 'seed', 'enhance_prompt', 'safety_checker', 'scheduler'
        ] );

        $response = $this->client()->post( 'api/v6/images/text2img', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $this->status( $data, $response );
        $status = $data['status'] ?? '';

        // ModelsLab queues longer jobs and returns the URL to fetch the result until done
        if( $status === 'processing' ) {
            return $this->queued( $data );
        }

        // no job was created, so the error may be temporary like a rate limit and isn't a failed job
        if( $status !== 'success' ) {
            throw new PrismaException( is_string( $data['message'] ?? null ) ? $data['message'] : 'ModelsLab request failed' );
        }

        return FileResponse::fromFiles( $this->images( $data['output'] ?? [] ) )->withMeta( array_diff_key( $data, ['output' => null] ) );
    }


    /**
     * Continues polling a queued generation.
     *
     * @param string $jobId Fetch URL returned by jobId()
     * @return FileResponse Asynchronous image response
     * @throws BadRequestException If the URL isn't a fetch URL of ModelsLab or the configured API host
     */
    public function resume( string $jobId ) : FileResponse
    {
        return FileResponse::fromAsync( $this->poll( $jobId ), $this->eta( [] ), jobId: $jobId );
    }


    /**
     * Builds Image instances from the ModelsLab output URLs.
     *
     * @param mixed $output Output entry from the response (list of URLs)
     * @return array<int, Image> Image files
     * @throws FailedException If the output contains no image URLs
     */
    protected function images( mixed $output ) : array
    {
        $files = [];

        foreach( (array) $output as $url )
        {
            if( is_string( $url ) && $url !== '' ) {
                $files[] = Image::fromUrl( $url );
            }
        }

        if( empty( $files ) ) {
            throw new FailedException( 'No image data found in response' );
        }

        return $files;
    }


    /**
     * Returns a polling closure that fetches a queued ModelsLab result.
     *
     * @param string $url Fetch URL returned by ModelsLab
     * @return \Closure Polling closure populating the file response
     * @throws BadRequestException If the URL isn't a fetch URL of ModelsLab or the configured API host
     */
    protected function poll( string $url ) : \Closure
    {
        // Never send the API key to a host or endpoint outside of the ModelsLab fetch API
        $uri = $this->jobUrl( $url, self::FETCH, '#^modelslab\.com$#D', 'Invalid ModelsLab fetch URL' );

        return function( FileResponse $fr ) use ( $uri ) : bool {

            $response = $this->client()->post( $uri, ['json' => ['key' => $this->apiKey], 'allow_redirects' => false] );

            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $this->status( $data, $response );

            // withMeta() replaces the meta data, so keep the fields of the submit response too
            $fr->withMeta( array_diff_key( $data, ['output' => null] ) + $fr->meta()->all() );
            $status = $data['status'] ?? '';

            // "provider_error" and errors of legacy endpoints without code are failed jobs
            if( $status === 'error' || $status === 'failed' ) {
                throw new FailedException( is_string( $data['message'] ?? null ) ? $data['message'] : 'ModelsLab job failed' );
            }

            if( $status === 'processing' )
            {
                // ModelsLab estimates the remaining time, so don't poll the job before that
                $fr->withRetry( $this->eta( $data ) );
                return false;
            }

            // unknown states stop waiting for the job but aren't reported as failed jobs, so it can be polled again
            if( $status !== 'success' ) {
                throw new PrismaException( is_string( $data['message'] ?? null ) ? $data['message'] : 'Unknown ModelsLab status' );
            }

            foreach( $this->images( $data['output'] ?? [] ) as $image ) {
                $fr->add( $image );
            }

            return true;
        };
    }


    /**
     * Returns the response polling a queued ModelsLab job.
     *
     * @param array<string, mixed> $data Initial response data
     * @return FileResponse Asynchronous file response
     * @throws FailedException If the response contains neither a fetch URL nor a request ID
     */
    protected function queued( array $data ) : FileResponse
    {
        $url = $data['fetch_result'] ?? null;
        $id = $data['id'] ?? null;

        // the fetch URL is optional, the request ID can be polled on the fetch endpoint too
        if( ( !is_string( $url ) || $url === '' ) && ( is_int( $id ) || is_string( $id ) && $id !== '' ) ) {
            $url = $this->apiUrl( 'api/v6/images/fetch/' . rawurlencode( $this->jobId( (string) $id ) ) );
        }

        if( !is_string( $url ) || $url === '' ) {
            throw new FailedException( 'No fetch URL in response' );
        }

        // ModelsLab estimates how long the generation takes, so poll not before that
        return FileResponse::fromAsync( $this->poll( $url ), $this->eta( $data ), jobId: $url )
            ->withMeta( array_diff_key( $data, ['output' => null] ) );
    }


    /**
     * Throws the typed exception for request errors ModelsLab reports in responses with HTTP status 200.
     *
     * The errors are temporary or caused by the request, so they aren't reported as failed jobs.
     * Only "provider_error" is reported by the model provider for the job itself.
     *
     * @param array<string, mixed> $data Response data with the error code
     * @param ResponseInterface $response HTTP response for the Retry-After header of rate limit errors
     * @throws PrismaException If the response reports a request error
     */
    protected function status( array $data, ResponseInterface $response ) : void
    {
        $code = $data['code'] ?? null;

        if( ( $data['status'] ?? null ) === 'error' && is_string( $code ) && $code !== 'provider_error' )
        {
            $msg = is_string( $data['message'] ?? null ) && $data['message'] !== ''
                ? $data['message']
                : 'ModelsLab error ' . $code;

            $this->throw( self::ERRORS[$code] ?? 500, $msg, $response );
        }
    }


    /**
     * Returns the seconds to wait before polling the job again.
     *
     * @param array<string, mixed> $data Response data with the estimated remaining time if available
     * @return int Estimated remaining time up to one minute, or the default interval if there's no estimate any more
     */
    private function eta( array $data ) : int
    {
        // polling without a timeout sleeps as long as estimated, so an estimate of hours must not stall it
        return ( $eta = (int) ( $data['eta'] ?? 0 ) ) > 0 ? min( $eta, 60 ) : 5;
    }
}
