<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Replicate extends Base implements Imagine
{
    private const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

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
     * Extracts the output image URLs from a prediction.
     *
     * @param array<string, mixed> $data Prediction data
     * @return array<int, string> Image URLs
     */
    protected function outputs( array $data ) : array
    {
        $output = $data['output'] ?? [];
        $output = is_array( $output ) ? $output : [$output];

        return array_values( array_filter( $output, fn( $url ) => is_string( $url ) && $url !== '' ) );
    }


    /**
     * Returns a polling closure that fetches a running prediction until it succeeds.
     *
     * @param string $url Prediction status URL (urls.get)
     * @return \Closure Polling closure populating the file response
     */
    protected function poll( string $url ) : \Closure
    {
        return function( FileResponse $fr ) use ( $url ) : bool {

            $response = $this->client()->get( $url );

            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? '';

            if( $status === 'failed' || $status === 'canceled' ) {
                throw new PrismaException( is_string( $data['error'] ?? null ) ? $data['error'] : 'Replicate prediction failed' );
            }

            if( $status !== 'succeeded' ) {
                return false;
            }

            foreach( $this->outputs( $data ) as $url ) {
                $fr->add( $this->image( $url ) );
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
        $status = $data['status'] ?? '';

        if( $status === 'failed' || $status === 'canceled' ) {
            throw new PrismaException( is_string( $data['error'] ?? null ) ? $data['error'] : 'Replicate prediction failed' );
        }

        if( $status !== 'succeeded' )
        {
            /** @var string|null $url */
            $url = $data['urls']['get'] ?? null;

            if( !$url ) {
                throw new PrismaException( 'No prediction status URL in response' );
            }

            return FileResponse::fromAsync( $this->poll( $url ), 3 );
        }

        $files = array_map( $this->image(...), $this->outputs( $data ) );

        if( empty( $files ) ) {
            throw new PrismaException( 'No image data found in response' );
        }

        $meta = $data;
        unset( $meta['output'] );

        return FileResponse::fromFiles( $files )->withMeta( $meta );
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
}
