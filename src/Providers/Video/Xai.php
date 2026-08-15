<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Xai as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Xai extends Base implements Imagine, Repaint
{
    use GeneratesVideo;


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        return $this->submit( 'v1/videos/generations', $this->request( $prompt, $media, $options ) );
    }


    public function repaint( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        $request = [
            'model' => $this->modelName( 'grok-imagine-video' ),
            'prompt' => $prompt,
            'video' => ['url' => $this->mediaUrl( $video )],
        ];

        if( is_array( $options['storage_options'] ?? null ) ) {
            $request['storage_options'] = $options['storage_options'];
        }

        return $this->submit( 'v1/videos/edits', $request );
    }


    /**
     * Submits an xAI video request.
     *
     * @param string $path API endpoint path
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Deferred video response
     */
    protected function submit( string $path, array $request ) : FileResponse
    {
        $response = $this->client()->post( $path, ['json' => $request] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['request_id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( is_string( $data['message'] ?? null ) ? $data['message'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    /**
     * Returns a closure that polls an xAI video request.
     *
     * @param string $id Request identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'v1/videos/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( $status === 'failed' || $status === 'expired' ) {
                $this->videoFailed( is_string( $data['message'] ?? null ) ? $data['message'] : $status );
            }

            if( $status !== 'done' ) {
                return false;
            }

            /** @var array<string, mixed> $video */
            $video = is_array( $data['video'] ?? null ) ? $data['video'] : [];
            $url = $video['url'] ?? null;

            if( !is_string( $url ) || $url === '' ) {
                $this->videoFailed();
            }

            $result->add( Video::fromUrl( $url, 'video/mp4' ) )->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the xAI video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $request = [
            'model' => $this->modelName( 'grok-imagine-video-1.5' ),
            'prompt' => $prompt,
        ];
        $map = [
            'duration' => 'duration',
            'aspectRatio' => 'aspect_ratio',
            'resolution' => 'resolution',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $request[$target] = $options[$source];
            }
        }

        $start = $media['start'] ?? null;

        if( $start instanceof Image ) {
            $request['image'] = ['url' => $this->mediaUrl( $start )];
            return $request;
        }

        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];

        foreach( $references as $reference ) {
            if( $reference instanceof Image && count( $request['reference_images'] ?? [] ) < 7 ) {
                $request['reference_images'][] = ['url' => $this->mediaUrl( $reference )];
            }
        }

        return $request;
    }
}
