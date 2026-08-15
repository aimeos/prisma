<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Omni extends Base implements Imagine, Repaint
{
    use GeneratesVideo;


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        return $this->submit( $this->request( $prompt, $media, $options ) );
    }


    public function repaint( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( $this->repaintRequest( $video, $prompt ) );
    }


    /**
     * Submits a Gemini Omni video request.
     *
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Video response
     */
    protected function submit( array $request ) : FileResponse
    {
        $response = $this->client()->post( 'v1beta/interactions', [
            'json' => $request,
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $files = $this->files( $data );

        if( empty( $files ) ) {
            $this->videoFailed( is_array( $data['error'] ?? null ) ? ( $data['error']['message'] ?? null ) : null );
        }

        unset( $data['steps'] );

        return FileResponse::fromFiles( $files )->withMeta( $data );
    }


    /**
     * Builds the Gemini Omni video editing request.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the changes
     * @return array<string, mixed> Request payload
     */
    protected function repaintRequest( Video $video, string $prompt ) : array
    {
        return [
            'model' => $this->modelName( 'gemini-omni-flash-preview' ),
            'input' => [[
                'type' => 'video',
                'data' => $video->base64(),
                'mime_type' => $video->mimeType() ?? 'video/mp4',
            ], [
                'type' => 'text',
                'text' => $prompt,
            ]],
            'response_format' => ['type' => 'video'],
            'generation_config' => ['video_config' => ['task' => 'edit']],
        ];
    }


    /**
     * Extracts generated videos from an Omni response.
     *
     * @param array<string, mixed> $data Response data
     * @return array<int, Video> Generated videos
     */
    protected function files( array $data ) : array
    {
        $files = [];

        foreach( is_array( $data['steps'] ?? null ) ? $data['steps'] : [] as $step )
        {
            if( !is_array( $step ) || ( $step['type'] ?? null ) !== 'model_output' ) {
                continue;
            }

            foreach( is_array( $step['content'] ?? null ) ? $step['content'] : [] as $part )
            {
                if( !is_array( $part ) || ( $part['type'] ?? null ) !== 'video' ) {
                    continue;
                }

                $mime = is_string( $part['mime_type'] ?? null ) ? $part['mime_type'] : 'video/mp4';

                if( is_string( $part['data'] ?? null ) ) {
                    $files[] = Video::fromBase64( $part['data'], $mime );
                } elseif( is_string( $part['uri'] ?? null ) ) {
                    $files[] = Video::fromUrl( $part['uri'], $mime );
                }
            }
        }

        return $files;
    }


    /**
     * Selects supported images from the semantic media input.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, Image> Selected images
     */
    protected function images( array $media ) : array
    {
        $start = $media['start'] ?? null;

        if( $start instanceof Image ) {
            return [$start];
        }

        $images = [];
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];

        foreach( $references as $reference ) {
            if( $reference instanceof Image ) {
                $images[] = $reference;
            }
        }

        return $images;
    }


    /**
     * Builds the Gemini Omni interaction request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $images = $this->images( $media );
        $input = [];

        foreach( $images as $image ) {
            $input[] = [
                'type' => 'image',
                'data' => $image->base64(),
                'mime_type' => $image->mimeType() ?? 'image/png',
            ];
        }

        $input[] = ['type' => 'text', 'text' => $prompt];
        $request = [
            'model' => $this->modelName( 'gemini-omni-flash-preview' ),
            'input' => count( $input ) === 1 ? $prompt : $input,
            'response_format' => ['type' => 'video'],
        ];

        if( is_string( $options['aspectRatio'] ?? null ) ) {
            $request['response_format']['aspect_ratio'] = $options['aspectRatio'];
        }

        if( !empty( $images ) ) {
            $request['generation_config'] = [
                'video_config' => [
                    'task' => ( $media['start'] ?? null ) instanceof Image ? 'image_to_video' : 'reference_to_video',
                ],
            ];
        }

        return $request;
    }
}
