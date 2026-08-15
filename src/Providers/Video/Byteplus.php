<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Byteplus extends Base implements Describe, Imagine, Repaint
{
    use CallsTools;
    use GeneratesVideo;
    use OpenaiApi;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://ark.ap-southeast.bytepluses.com' ) );
    }


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $input = [[
            'role' => 'user',
            'content' => [[
                'type' => 'input_video',
                'video_url' => $this->mediaUrl( $video ),
            ] + $this->allowed( $options, ['fps'] ), [
                'type' => 'input_text',
                'text' => 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
                    . ( $lang ?? 'en' ) . '".',
            ]],
        ]];

        return $this->responses(
            'api/v3/responses',
            'seed-2-0-lite-260228',
            $input,
            $this->allowed( $options, ['temperature', 'top_p', 'thinking'] )
        );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'api/v3/contents/generations/tasks', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( is_string( $data['message'] ?? null ) ? $data['message'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $id ), 5 );
    }


    public function repaint( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->imagine( $prompt, ['references' => [$video]], $options );
    }


    /**
     * Builds a BytePlus content entry for a media file.
     *
     * @param Audio|Image|Video $file Media file
     * @param string $role BytePlus media role
     * @return array<string, mixed> Content entry
     */
    protected function mediaContent( Audio|Image|Video $file, string $role ) : array
    {
        $type = match( true ) {
            $file instanceof Image => 'image_url',
            $file instanceof Video => 'video_url',
            default => 'audio_url',
        };

        return [
            'type' => $type,
            $type => ['url' => $this->mediaUrl( $file )],
            'role' => $role,
        ];
    }


    /**
     * Builds the BytePlus prompt and media content list.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array<string, mixed>> Content entries
     */
    protected function contents( string $prompt, array $media ) : array
    {
        $content = [['type' => 'text', 'text' => $prompt]];
        $start = $media['start'] ?? null;

        if( $start instanceof Image )
        {
            $content[] = $this->mediaContent( $start, 'first_frame' );

            if( ( $media['end'] ?? null ) instanceof Image ) {
                $content[] = $this->mediaContent( $media['end'], 'last_frame' );
            }

            return $content;
        }

        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];
        $visual = array_filter( $references, fn( mixed $item ) => $item instanceof Image || $item instanceof Video );

        // Seedance rejects audio-only reference mode.
        if( !empty( $visual ) ) {
            foreach( $references as $reference ) {
                if( $reference instanceof Image ) {
                    $content[] = $this->mediaContent( $reference, 'reference_image' );
                } elseif( $reference instanceof Video ) {
                    $content[] = $this->mediaContent( $reference, 'reference_video' );
                } elseif( $reference instanceof Audio ) {
                    $content[] = $this->mediaContent( $reference, 'reference_audio' );
                }
            }
        }

        return $content;
    }


    /**
     * Returns a closure that polls a BytePlus generation task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'api/v3/contents/generations/tasks/' . rawurlencode( $id ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( in_array( $status, ['failed', 'cancelled'], true ) ) {
                $this->videoFailed( is_string( $data['error'] ?? null ) ? $data['error'] : $status );
            }

            if( $status !== 'succeeded' ) {
                return false;
            }

            /** @var array<string, mixed> $output */
            $output = is_array( $data['content'] ?? null ) ? $data['content'] : [];
            $url = $output['video_url'] ?? null;

            if( !is_string( $url ) || $url === '' ) {
                $this->videoFailed();
            }

            $result->add( Video::fromUrl( $url, 'video/mp4' ) )->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the BytePlus video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $request = [
            'model' => $this->modelName( 'dreamina-seedance-2-0-260128' ),
            'content' => $this->contents( $prompt, $media ),
        ];
        $map = [
            'duration' => 'duration',
            'resolution' => 'resolution',
            'aspectRatio' => 'ratio',
            'audio' => 'generate_audio',
            'seed' => 'seed',
            'watermark' => 'watermark',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $request[$target] = $options[$source];
            }
        }

        return $request;
    }
}
