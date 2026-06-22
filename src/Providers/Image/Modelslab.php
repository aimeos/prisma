<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Modelslab extends Base implements Imagine
{
    private string $apiKey;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->apiKey = $this->cfg( $config, 'api_key' );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://modelslab.com' ) );
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
        $status = $data['status'] ?? '';

        // ModelsLab queues longer jobs and returns a "fetch_result" URL to poll until done
        if( $status === 'processing' && !empty( $data['fetch_result'] ) ) {
            return FileResponse::fromAsync( $this->poll( (string) $data['fetch_result'] ), max( 1, (int) ( $data['eta'] ?? 5 ) ) );
        }

        if( $status !== 'success' ) {
            throw new PrismaException( is_string( $data['message'] ?? null ) ? $data['message'] : 'ModelsLab request failed' );
        }

        $meta = $data;
        unset( $meta['output'] );

        return FileResponse::fromFiles( $this->images( $data['output'] ?? [] ) )->withMeta( $meta );
    }


    /**
     * Builds Image instances from the ModelsLab output URLs.
     *
     * @param mixed $output Output entry from the response (list of URLs)
     * @return array<int, Image> Image files
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
            throw new PrismaException( 'No image data found in response' );
        }

        return $files;
    }


    /**
     * Returns a polling closure that fetches a queued ModelsLab result.
     *
     * @param string $url Fetch-result URL returned by the initial request
     * @return \Closure Polling closure populating the file response
     */
    protected function poll( string $url ) : \Closure
    {
        return function( FileResponse $fr ) use ( $url ) : bool {

            $response = $this->client()->post( $url, ['json' => ['key' => $this->apiKey]] );

            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );

            if( ( $data['status'] ?? '' ) !== 'success' ) {
                return false;
            }

            foreach( $this->images( $data['output'] ?? [] ) as $image ) {
                $fr->add( $image );
            }

            return true;
        };
    }
}
