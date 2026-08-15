<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Extend;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Alibaba as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Alibaba extends Base implements Describe, Extend, Imagine, Repaint
{
    use GeneratesVideo;


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $content = [[
            'type' => 'video_url',
            'video_url' => ['url' => $this->mediaUrl( $video )]
                + $this->allowed( $options, ['fps'] ),
        ] + $this->allowed( $options, ['min_pixels', 'max_pixels', 'total_pixels'] ), [
            'type' => 'text',
            'text' => 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
                . ( $lang ?? 'en' ) . '".',
        ]];

        return $this->completions(
            'compatible-mode/v1/chat/completions',
            'qwen3.7-plus',
            $this->messages( $content ),
            $this->allowed( $options, ['temperature', 'top_p', 'top_k'] )
        );
    }


    public function extend( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( $this->extendRequest( $video, $prompt, $options ) );
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
     * Submits an Alibaba video request.
     *
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Deferred video response
     */
    protected function submit( array $request ) : FileResponse
    {
        $response = $this->client()->post( 'api/v1/services/aigc/video-generation/video-synthesis', [
            'headers' => ['X-DashScope-Async' => 'enable'],
            'json' => $request,
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
     * Builds the Alibaba video continuation request.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the continuation
     * @param array<string, mixed> $options Provider specific options
     * @return array<string, mixed> Request payload
     */
    protected function extendRequest( Video $video, string $prompt, array $options ) : array
    {
        $input = [
            'prompt' => $prompt,
            'media' => [['type' => 'first_clip', 'url' => $this->mediaUrl( $video )]],
        ];

        if( is_string( $options['negative_prompt'] ?? null ) ) {
            $input['negative_prompt'] = $options['negative_prompt'];
        }

        $parameters = $this->allowed( $options, [
            'resolution', 'duration', 'prompt_extend', 'watermark', 'seed',
        ] );
        $request = ['model' => $this->modelName( 'wan2.7-i2v' ), 'input' => $input];

        if( !empty( $parameters ) ) {
            $request['parameters'] = $parameters;
        }

        return $request;
    }


    /**
     * Builds the Alibaba video editing request.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the changes
     * @param array<string, mixed> $options Provider specific options
     * @return array<string, mixed> Request payload
     */
    protected function repaintRequest( Video $video, string $prompt, array $options ) : array
    {
        $input = [
            'prompt' => $prompt,
            'media' => [['type' => 'video', 'url' => $this->mediaUrl( $video )]],
        ];

        if( is_string( $options['negative_prompt'] ?? null ) ) {
            $input['negative_prompt'] = $options['negative_prompt'];
        }

        $parameters = $this->allowed( $options, [
            'resolution', 'duration', 'audio_setting', 'prompt_extend', 'watermark', 'seed',
        ] );

        if( is_string( $options['aspectRatio'] ?? null ) ) {
            $parameters['ratio'] = $options['aspectRatio'];
        }

        $request = ['model' => $this->modelName( 'wan2.7-videoedit' ), 'input' => $input];

        if( !empty( $parameters ) ) {
            $request['parameters'] = $parameters;
        }

        return $request;
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
