<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Minimax extends Base implements Imagine
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.minimax.io' ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'v1/video_generation', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['task_id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $base = is_array( $data['base_resp'] ?? null ) ? $data['base_resp'] : [];
            $this->videoFailed( is_string( $base['status_msg'] ?? null ) ? $base['status_msg'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    /**
     * Returns a closure that polls a MiniMax generation task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'v1/query/video_generation?task_id=' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( $status === 'Fail' ) {
                $this->videoFailed( is_array( $data['base_resp'] ?? null ) ? ( $data['base_resp']['status_msg'] ?? null ) : null );
            }

            if( $status !== 'Success' ) {
                return false;
            }

            $fileId = $data['file_id'] ?? null;

            if( !is_string( $fileId ) && !is_int( $fileId ) ) {
                $this->videoFailed();
            }

            $result->add( $this->video( $fileId ) )->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the MiniMax video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $start = $media['start'] ?? null;
        $end = $media['end'] ?? null;
        $subjects = $this->subjects( $media );

        $default = !empty( $subjects ) ? 'S2V-01'
            : ( $start instanceof Image && $end instanceof Image ? 'MiniMax-Hailuo-02' : 'MiniMax-Hailuo-2.3' );
        $request = [
            'model' => $this->modelName( $default ),
            'prompt' => $prompt,
        ];

        if( $start instanceof Image ) {
            $request['first_frame_image'] = $this->mediaUrl( $start );

            if( $end instanceof Image ) {
                $request['last_frame_image'] = $this->mediaUrl( $end );
            }
        } elseif( !empty( $subjects ) ) {
            $request['subject_reference'] = [[
                'type' => 'character',
                'image' => $subjects,
            ]];
        }

        $map = [
            'duration' => 'duration',
            'resolution' => 'resolution',
            'seed' => 'seed',
            'prompt_optimizer' => 'prompt_optimizer',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $request[$target] = $options[$source];
            }
        }

        return $request;
    }


    /**
     * Returns supported MiniMax subject reference URLs.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, string> Subject image URLs
     */
    protected function subjects( array $media ) : array
    {
        if( ( $media['start'] ?? null ) instanceof Image ) {
            return [];
        }

        $subjects = [];
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];

        foreach( $references as $reference ) {
            if( $reference instanceof Image ) {
                $subjects[] = $this->mediaUrl( $reference );
            }
        }

        return $subjects;
    }


    /**
     * Retrieves a completed MiniMax video file.
     *
     * @param string|int $id File identifier
     * @return Video Generated video
     */
    protected function video( string|int $id ) : Video
    {
        $response = $this->client()->get( 'v1/files/retrieve?file_id=' . rawurlencode( (string) $id ) );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $file */
        $file = is_array( $data['file'] ?? null ) ? $data['file'] : [];
        $url = $file['download_url'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            $this->videoFailed();
        }

        return Video::fromUrl( $url, 'video/mp4' );
    }
}
