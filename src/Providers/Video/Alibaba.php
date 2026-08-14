<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Alibaba as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Alibaba extends Base implements Imagine
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        parent::__construct( $config );
        $this->header( 'X-DashScope-Async', 'enable' );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'api/v1/services/aigc/video-generation/video-synthesis', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $output */
        $output = is_array( $data['output'] ?? null ) ? $data['output'] : [];
        $id = $output['task_id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( is_string( $data['message'] ?? null ) ? $data['message'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    /**
     * Maps supported media and selects the matching default Wan model.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array{0: string, 1: array<int, array{type: string, url: string}>} Model and media entries
     */
    protected function media( array $media ) : array
    {
        $start = $media['start'] ?? null;
        $end = $media['end'] ?? null;
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];
        $mapped = [];

        if( $start instanceof Image )
        {
            $mapped[] = ['type' => 'first_frame', 'url' => $this->mediaUrl( $start )];

            if( $end instanceof Image ) {
                $mapped[] = ['type' => 'last_frame', 'url' => $this->mediaUrl( $end )];
            }

            foreach( $references as $reference ) {
                if( $reference instanceof Audio ) {
                    $mapped[] = ['type' => 'driving_audio', 'url' => $this->mediaUrl( $reference )];
                    break;
                }
            }

            return ['wan2.7-i2v', $mapped];
        }

        foreach( $references as $reference ) {
            $type = match( true ) {
                $reference instanceof Image => 'reference_image',
                $reference instanceof Video => 'reference_video',
                $reference instanceof Audio => 'reference_audio',
                default => null,
            };

            if( $type ) {
                $mapped[] = ['type' => $type, 'url' => $this->mediaUrl( $reference )];
            }
        }

        return [empty( $mapped ) ? 'wan2.7-t2v' : 'wan2.7-r2v', $mapped];
    }


    /**
     * Returns a closure that polls an Alibaba video task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'api/v1/tasks/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            /** @var array<string, mixed> $output */
            $output = is_array( $data['output'] ?? null ) ? $data['output'] : [];
            $status = $output['task_status'] ?? null;

            if( $status === 'FAILED' ) {
                $this->videoFailed( is_string( $output['message'] ?? null ) ? $output['message'] : null );
            }

            if( $status !== 'SUCCEEDED' ) {
                return false;
            }

            $url = $output['video_url'] ?? null;

            if( !is_string( $url ) || $url === '' ) {
                $this->videoFailed();
            }

            $result->add( Video::fromUrl( $url, 'video/mp4' ) )->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the Alibaba video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        [$default, $mapped] = $this->media( $media );
        $input = ['prompt' => $prompt];

        if( !empty( $mapped ) ) {
            $input['media'] = $mapped;
        }

        $parameters = [];
        $map = [
            'duration' => 'duration',
            'resolution' => 'resolution',
            'aspectRatio' => 'ratio',
            'seed' => 'seed',
            'negative_prompt' => 'negative_prompt',
            'prompt_extend' => 'prompt_extend',
            'watermark' => 'watermark',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $parameters[$target] = $options[$source];
            }
        }

        $request = ['model' => $this->modelName( $default ), 'input' => $input];

        if( !empty( $parameters ) ) {
            $request['parameters'] = $parameters;
        }

        return $request;
    }
}
