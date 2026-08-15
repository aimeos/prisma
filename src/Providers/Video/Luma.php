<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Luma extends Base implements Imagine, Repaint
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( rtrim( $this->config( $config, 'url', 'https://agents.lumalabs.ai/v1' ), '/' ) . '/' );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        return $this->submit( $this->request( $prompt, $media, $options ) );
    }


    public function repaint( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( $this->repaintRequest( $video, $prompt, $options ) );
    }


    /**
     * Submits a Luma video request.
     *
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Deferred video response
     */
    protected function submit( array $request ) : FileResponse
    {
        $response = $this->client()->post( 'generations', [
            'json' => $request,
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
     * Builds the Luma video editing request.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the changes
     * @param array<string, mixed> $options Provider specific options
     * @return array<string, mixed> Request payload
     */
    protected function repaintRequest( Video $video, string $prompt, array $options ) : array
    {
        $edit = [];
        $strengths = [
            'adhere_1', 'adhere_2', 'adhere_3',
            'flex_1', 'flex_2', 'flex_3',
            'reimagine_1', 'reimagine_2', 'reimagine_3',
        ];

        if( in_array( $options['strength'] ?? null, $strengths, true ) ) {
            $edit['strength'] = $options['strength'];
        }

        if( is_array( $options['controls'] ?? null ) ) {
            $edit['controls'] = $options['controls'];
        }

        if( is_bool( $options['auto_controls'] ?? null ) ) {
            $edit['auto_controls'] = $options['auto_controls'];
        } elseif( empty( $edit ) ) {
            $edit['auto_controls'] = true;
        }

        return [
            'prompt' => $prompt,
            'model' => $this->modelName( 'ray-3.2' ),
            'type' => 'video_edit',
            'source' => $this->videoReference( $video ),
            'video' => [
                'resolution' => $this->resolution( $options['resolution'] ?? null ),
                'edit' => $edit,
            ],
        ];
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
     * Returns a Luma-style video source.
     *
     * @param Video $video Input video object
     * @return array<string, string|null> URL or inline media reference
     */
    protected function videoReference( Video $video ) : array
    {
        $source = ['media_type' => $video->mimeType() ?? 'video/mp4'];

        if( $url = $video->url() ) {
            return ['url' => $url] + $source;
        }

        return ['data' => $video->base64()] + $source;
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
