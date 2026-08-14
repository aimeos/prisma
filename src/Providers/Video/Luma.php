<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Luma extends Base implements Imagine
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://agents.lumalabs.ai/v1' ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'generations', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( is_string( $data['failure_reason'] ?? null ) ? $data['failure_reason'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    /**
     * Returns a closure that polls a Luma generation.
     *
     * @param string $id Generation identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'generations/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $state = $data['state'] ?? null;

            if( $state === 'failed' ) {
                $this->videoFailed( is_string( $data['failure_reason'] ?? null ) ? $data['failure_reason'] : null );
            }

            if( $state !== 'completed' ) {
                return false;
            }

            foreach( is_array( $data['output'] ?? null ) ? $data['output'] : [] as $output ) {
                $url = is_array( $output ) && $output['type'] === 'video' ? ( $output['url'] ?? null ) : null;

                if( is_string( $url ) && $url !== '' ) {
                    $result->add( Video::fromUrl( $url, 'video/mp4' ) );
                }
            }

            if( $result->empty() ) {
                $this->videoFailed();
            }

            $result->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the Luma video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $video = [
            'duration' => ( $options['duration'] ?? null ) === 10 ? '10s' : '5s',
            'resolution' => $this->resolution( $options['resolution'] ?? null ),
        ];
        $start = $media['start'] ?? null;

        if( $start instanceof Image ) {
            $video['start_frame'] = $this->mediaReference( $start );

            if( ( $media['end'] ?? null ) instanceof Image ) {
                $video['end_frame'] = $this->mediaReference( $media['end'] );
            }
        }

        if( is_bool( $options['loop'] ?? null ) ) {
            $video['loop'] = $options['loop'];
        }

        foreach( ['hdr', 'exr_export'] as $name ) {
            if( is_bool( $options[$name] ?? null ) ) {
                $video[$name] = $options[$name];
            }
        }

        $request = [
            'prompt' => $prompt,
            'model' => $this->modelName( 'ray-3.2' ),
            'type' => 'video',
            'video' => $video,
        ];

        if( in_array( $options['aspectRatio'] ?? null, ['16:9', '9:16', '1:1'], true ) ) {
            $request['aspect_ratio'] = $options['aspectRatio'];
        }

        return $request;
    }


    /**
     * Returns a Luma-style media reference.
     *
     * @param Image $image Frame image
     * @return array<string, string|null> URL or inline media reference
     */
    protected function mediaReference( Image $image ) : array
    {
        if( $url = $image->url() ) {
            return ['url' => $url];
        }

        return [
            'data' => $image->base64(),
            'media_type' => $image->mimeType() ?? 'application/octet-stream',
        ];
    }


    /**
     * Normalizes the requested Luma output resolution.
     *
     * @param mixed $resolution Requested resolution
     * @return string Supported resolution
     */
    protected function resolution( mixed $resolution ) : string
    {
        return in_array( $resolution, ['360p', '540p', '720p', '1080p'], true )
            ? $resolution
            : '720p';
    }
}
