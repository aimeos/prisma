<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use GuzzleHttp\Psr7\Uri;


class Replicate extends Base implements Cancel, Imagine, Resume
{
    private const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;
    private const PREDICTION = '#/v\d+/predictions/\w[\w.-]*$#D';

    /** @var array<int, string> */
    private array $downloadHosts;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $hosts = $config['download_hosts'] ?? ['replicate.delivery'];
        $this->downloadHosts = is_array( $hosts )
            ? array_values( array_filter( $hosts, 'is_string' ) )
            : ['replicate.delivery'];

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.replicate.com' ) );
    }


    /**
     * Cancels a starting or processing prediction.
     *
     * @param string $jobId Prediction URL returned by jobId()
     * @return void
     * @throws BadRequestException If the URL isn't a prediction URL of Replicate or the configured API host
     */
    public function cancel( string $jobId ) : void
    {
        $uri = $this->url( $jobId );
        $response = $this->client()->post( $uri->withPath( $uri->getPath() . '/cancel' )->withQuery( '' ), ['allow_redirects' => false] );

        $this->validate( $response );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'black-forest-labs/flux-schnell' );
        $input = ['prompt' => $prompt] + $this->allowed( $options, [
            'aspect_ratio', 'num_outputs', 'seed', 'output_format', 'output_quality',
            'width', 'height', 'negative_prompt', 'guidance', 'num_inference_steps'
        ] );

        // "Prefer: wait" makes Replicate hold the request open until the prediction
        // finishes (up to ~60s) so most calls return the result without polling.
        $response = $this->client()->post( 'v1/models/' . $model . '/predictions', [
            'headers' => ['Prefer' => 'wait'],
            'json' => ['input' => $input],
        ] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        return $this->toFileResponse( $data );
    }


    /**
     * Continues polling a running prediction.
     *
     * @param string $jobId Prediction URL returned by jobId()
     * @return FileResponse Asynchronous image response
     * @throws BadRequestException If the URL isn't a prediction URL of Replicate or the configured API host
     */
    public function resume( string $jobId ) : FileResponse
    {
        return FileResponse::fromAsync( $this->poll( $jobId ), 3, jobId: $jobId );
    }


    /**
     * Returns whether a prediction has succeeded.
     *
     * @param array<string, mixed> $data Prediction data
     * @return bool TRUE if the prediction succeeded, FALSE if it is still running
     * @throws FailedException If the prediction failed or was canceled
     */
    protected function completed( array $data ) : bool
    {
        if( in_array( $data['status'] ?? null, ['aborted', 'canceled', 'failed'], true ) ) {
            throw new FailedException( is_string( $data['error'] ?? null ) ? $data['error'] : 'Replicate prediction failed' );
        }

        return ( $data['status'] ?? null ) === 'succeeded';
    }


    /**
     * Builds the output images of a succeeded prediction.
     *
     * @param array<string, mixed> $data Prediction data
     * @return array<int, Image> Image files
     * @throws FailedException If the prediction has no output images
     */
    protected function images( array $data ) : array
    {
        $output = $data['output'] ?? [];
        $output = is_array( $output ) ? $output : [$output];
        $urls = array_filter( $output, fn( $url ) => is_string( $url ) && $url !== '' );

        if( empty( $urls ) ) {
            throw new FailedException( 'No image data found in response' );
        }

        return array_values( array_map( $this->image(...), $urls ) );
    }


    /**
     * Returns a polling closure that fetches a running prediction until it succeeds.
     *
     * @param string $url Prediction URL returned by Replicate
     * @return \Closure Polling closure populating the file response
     * @throws BadRequestException If the URL isn't a prediction URL of Replicate or the configured API host
     */
    protected function poll( string $url ) : \Closure
    {
        $uri = $this->url( $url );

        return function( FileResponse $fr ) use ( $uri ) : bool {

            $response = $this->client()->get( $uri, ['allow_redirects' => false] );

            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $fr->withMeta( array_diff_key( $data, ['output' => null] ) );

            if( !$this->completed( $data ) ) {
                return false;
            }

            foreach( $this->images( $data ) as $image ) {
                $fr->add( $image );
            }

            return true;
        };
    }


    /**
     * Builds the file response from a prediction, polling if it has not finished yet.
     *
     * @param array<string, mixed> $data Prediction data
     * @return FileResponse File based response
     */
    protected function toFileResponse( array $data ) : FileResponse
    {
        if( !$this->completed( $data ) )
        {
            // Replicate hands out the URL of the prediction, which may differ from the default one
            $url = $data['urls']['get'] ?? null;

            if( !is_string( $url ) || $url === '' ) {
                throw new FailedException( 'No prediction URL in response' );
            }

            return $this->resume( $url )->withMeta( array_diff_key( $data, ['output' => null] ) );
        }

        return FileResponse::fromFiles( $this->images( $data ) )->withMeta( array_diff_key( $data, ['output' => null] ) );
    }


    /**
     * Builds a lazy image whose eventual download stays on Replicate's HTTPS CDN.
     */
    private function image( string $url ) : Image
    {
        $image = Image::fromUrl( $url );
        $image->restrictHosts( $this->downloadHosts );
        $image->maxSize( self::MAX_DOWNLOAD_BYTES );

        return $image;
    }


    /**
     * Validates a prediction URL returned by Replicate.
     *
     * @param string $url Prediction URL of the submit or status response
     * @return Uri Validated prediction URL
     * @throws BadRequestException If the URL isn't a prediction URL of Replicate or the configured API host
     */
    private function url( string $url ) : Uri
    {
        // Never send Replicate credentials to an unrelated host or endpoint, but its prediction URLs
        // point to the API host of Replicate even if the requests are sent through a gateway
        return $this->jobUrl( $url, self::PREDICTION, '#^api\.replicate\.com$#D', 'Invalid Replicate prediction URL' );
    }
}
