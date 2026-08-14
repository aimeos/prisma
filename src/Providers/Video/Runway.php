<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Runway extends Base implements Imagine
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->header( 'X-Runway-Version', '2024-11-06' );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.dev.runwayml.com' ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $frames = $this->frames( $media );
        $path = empty( $frames ) ? 'v1/text_to_video' : 'v1/image_to_video';
        $response = $this->client()->post( $path, [
            'json' => $this->request( $prompt, $frames, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( is_string( $data['error'] ?? null ) ? $data['error'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    /**
     * Builds supported Runway frame entries.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array{uri: string, position: string}> Frame entries
     */
    protected function frames( array $media ) : array
    {
        $frames = [];

        if( ( $media['start'] ?? null ) instanceof Image ) {
            $frames[] = ['uri' => $this->mediaUrl( $media['start'] ), 'position' => 'first'];
        }

        if( ( $media['end'] ?? null ) instanceof Image ) {
            $frames[] = ['uri' => $this->mediaUrl( $media['end'] ), 'position' => 'last'];
        }

        return $frames;
    }


    /**
     * Returns a closure that polls a Runway task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'v1/tasks/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( $status === 'FAILED' || $status === 'CANCELED' ) {
                $this->videoFailed( is_string( $data['failure'] ?? null ) ? $data['failure'] : $status );
            }

            if( $status !== 'SUCCEEDED' ) {
                return false;
            }

            foreach( is_array( $data['output'] ?? null ) ? $data['output'] : [] as $url ) {
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
     * Builds the Runway video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<int, array{uri: string, position: string}> $frames Frame entries
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $frames, array $options ) : array
    {
        $request = [
            'model' => $this->modelName( 'gen4.5' ),
            'promptText' => $prompt,
            'ratio' => $this->ratio( $options['aspectRatio'] ?? null, !empty( $frames ) ),
            'duration' => is_int( $options['duration'] ?? null ) ? $options['duration'] : 5,
        ];

        if( is_int( $options['seed'] ?? null ) ) {
            $request['seed'] = $options['seed'];
        }

        if( !empty( $frames ) ) {
            $request['promptImage'] = $frames;
        }

        return $request;
    }


    /**
     * Maps a common aspect ratio to a Runway output ratio.
     *
     * @param mixed $ratio Requested aspect ratio
     * @param bool $image Whether the request contains frames
     * @return string Runway output ratio
     */
    protected function ratio( mixed $ratio, bool $image ) : string
    {
        if( !$image ) {
            return $ratio === '9:16' ? '720:1280' : '1280:720';
        }

        return match( $ratio ) {
            '9:16' => '720:1280',
            '1:1' => '960:960',
            '4:3' => '1104:832',
            '3:4' => '832:1104',
            '21:9' => '1584:672',
            default => '1280:720',
        };
    }
}
