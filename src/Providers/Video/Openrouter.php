<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Openrouter extends Base implements Describe, Imagine
{
    use GeneratesVideo;


    protected int $pollTimeout = 900;


    public function __construct( array $config )
    {
        parent::__construct( $config );
        $timeout = $config['poll_timeout'] ?? null;

        if( is_int( $timeout ) && $timeout >= 0 ) {
            $this->pollTimeout = $timeout;
        } elseif( is_string( $timeout ) && preg_match( '/^\d+$/D', $timeout ) ) {
            $this->pollTimeout = (int) $timeout;
        }
    }


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning', 'provider'] );

        return $this->completions(
            'api/v1/chat/completions', 'google/gemini-3.7-flash',
            $this->messages( $this->content( $prompt, [$video] ) ),
            $options
        );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $request = [
            'model' => $this->modelName( 'google/veo-3.1' ),
            'prompt' => $prompt,
        ] + $this->allowed( $options, [
            'callback_url', 'duration', 'generate_audio', 'provider', 'resolution', 'seed', 'size'
        ] );

        if( isset( $options['aspectRatio'] ) ) {
            $request['aspect_ratio'] = $options['aspectRatio'];
        }

        $frames = $this->frames( $media );

        if( $frames ) {
            $request['frame_images'] = $frames;
        } elseif( $references = $this->references( $media ) ) {
            $request['input_references'] = $references;
        }

        return $this->submit( $request );
    }


    /**
     * Submits an OpenRouter video generation request.
     *
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Deferred video response
     */
    protected function submit( array $request ) : FileResponse
    {
        $response = $this->client()->post( 'api/v1/videos', ['json' => $request] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( $this->errorMessage( $data ) );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5, $this->pollTimeout );
    }


    /**
     * Returns a closure that polls an OpenRouter video job.
     *
     * @param string $id Job identifier
     * @return \Closure Polling closure
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'api/v1/videos/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( !in_array( $status, ['pending', 'in_progress', 'completed'], true ) ) {
                $this->videoFailed( $this->errorMessage( $data ) ?: ( is_string( $status ) ? $status : null ) );
            }

            if( $status !== 'completed' ) {
                return false;
            }

            /** @var array<int, mixed> $urls */
            $urls = is_array( $data['unsigned_urls'] ?? null ) ? $data['unsigned_urls'] : [];

            foreach( $urls as $url ) {
                if( is_string( $url ) && $url !== '' ) {
                    $result->add( Video::fromUrl( $url, 'video/mp4' ) );
                }
            }

            if( $result->empty() ) {
                $this->videoFailed();
            }

            /** @var array<string, mixed> $usage */
            $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
            $used = $usage['cost'] ?? null;

            $result->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )->withMeta( $data );
            return true;
        };
    }


    /**
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array<string, mixed>>
     */
    protected function frames( array $media ) : array
    {
        $frames = [];
        $map = ['start' => 'first_frame', 'end' => 'last_frame'];

        foreach( $map as $key => $type )
        {
            if( ( $image = $media[$key] ?? null ) instanceof Image ) {
                $frames[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $this->mediaUrl( $image )],
                    'frame_type' => $type,
                ];
            }
        }

        return $frames;
    }


    /**
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array<string, mixed>>
     */
    protected function references( array $media ) : array
    {
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];
        $result = [];

        foreach( $references as $reference )
        {
            $type = match( true ) {
                $reference instanceof Image => 'image_url',
                $reference instanceof Audio => 'audio_url',
                $reference instanceof Video => 'video_url',
                default => null,
            };

            if( $type ) {
                $result[] = [
                    'type' => $type,
                    $type => ['url' => $this->mediaUrl( $reference )],
                ];
            }
        }

        return $result;
    }


    /** @param array<string, mixed> $data */
    protected function errorMessage( array $data ) : ?string
    {
        $error = $data['error'] ?? null;

        if( is_string( $error ) ) {
            return $error;
        }

        if( is_array( $error ) && is_string( $error['message'] ?? null ) ) {
            return $error['message'];
        }

        return is_string( $data['message'] ?? null ) ? $data['message'] : null;
    }
}
