<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Kling extends Base implements Imagine, Repaint, Resume
{
    use GeneratesVideo;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api-singapore.klingai.com' ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $prompt = ['type' => 'prompt', 'text' => $prompt];

        // frames take precedence over references which require the Omni model
        if( !empty( $frames = $this->frames( $media ) ) ) {
            return $this->submit( 'image-to-video/' . $this->modelName( 'kling-3.0' ), [
                'contents' => array_merge( [$prompt], $frames ),
                'settings' => $this->settings( $options, false ),
            ] );
        }

        if( !empty( $references = $this->references( $media ) ) ) {
            return $this->submit( 'omni-video/' . $this->modelName( 'kling-3.0-omni' ), [
                'contents' => array_merge( [$prompt], $references ),
                'settings' => $this->settings( $options, true ),
            ] );
        }

        return $this->submit( 'text-to-video/' . $this->modelName( 'kling-3.0' ), [
            'contents' => [$prompt],
            'settings' => $this->settings( $options, true ),
        ] );
    }


    public function repaint( Video $video, string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        [$media, $options] = $this->repaintArguments( $media, $options );

        $images = array_filter( $this->references( $media ), fn( array $item ) => $item['type'] === 'refer_image' );
        $contents = array_merge( [
            ['type' => 'prompt', 'text' => $prompt],
            ['type' => 'base_video', 'url' => $this->source( $video )],
        ], array_slice( $images, 0, 4 ) );

        // the edited video can keep its original sound but no native audio can be generated
        $settings = ['audio' => ( $options['audio'] ?? false ) === true ? 'original' : 'off']
            + $this->settings( $options, false );

        return $this->submit( 'omni-video/' . $this->modelName( 'kling-3.0-omni' ), [
            'contents' => $contents,
            'settings' => $settings,
        ] );
    }


    public function resume( string $jobId ) : FileResponse
    {
        $jobId = $this->jobId( $jobId );

        return FileResponse::fromAsync( $this->poll( $jobId ), 5, jobId: $jobId );
    }


    /**
     * Builds the first and last frame entries.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array{type: string, url: string}> Frame entries
     */
    protected function frames( array $media ) : array
    {
        // only "first frame" and "first and last frame" are supported, not "last frame only"
        if( !( ( $media['start'] ?? null ) instanceof Image ) ) {
            return [];
        }

        $frames = [['type' => 'first_frame', 'url' => $this->source( $media['start'] )]];

        if( ( $media['end'] ?? null ) instanceof Image ) {
            $frames[] = ['type' => 'last_frame', 'url' => $this->source( $media['end'] )];
        }

        return $frames;
    }


    /**
     * Returns a closure that polls a Kling task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'tasks?task_ids=' . rawurlencode( $id ) );
            $this->validate( $response );

            $data = $this->fromJson( $response );
            /** @var array<string, mixed> $task */
            $task = is_array( $data['data'][0] ?? null ) ? $data['data'][0] : [];
            $result->withMeta( $task );
            $status = $task['status'] ?? null;

            if( $status === 'failed' ) {
                $this->videoFailed( $task['message'] ?? null );
            }

            if( $status !== 'succeeded' ) {
                return false;
            }

            foreach( is_array( $task['outputs'] ?? null ) ? $task['outputs'] : [] as $output ) {
                if( ( $output['type'] ?? null ) === 'video' && is_string( $output['url'] ?? null ) && $output['url'] !== '' ) {
                    $result->add( $this->urlFile( $output['url'], Video::class, 'video/mp4' ) );
                }
            }

            if( $result->empty() ) {
                $this->videoFailed();
            }

            return true;
        };
    }


    /**
     * Builds the reference image and video entries for the Omni model.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, array{type: string, url: string}> Reference entries
     */
    protected function references( array $media ) : array
    {
        $images = $videos = [];

        foreach( is_array( $media['references'] ?? null ) ? $media['references'] : [] as $reference )
        {
            if( $reference instanceof Image ) {
                $images[] = ['type' => 'refer_image', 'url' => $this->source( $reference )];
            } elseif( $reference instanceof Video ) {
                $videos[] = ['type' => 'feature_video', 'url' => $this->source( $reference )];
            }
        }

        // one reference video at most and fewer reference images if a video is used
        $videos = array_slice( $videos, 0, 1 );

        return array_merge( array_slice( $images, 0, empty( $videos ) ? 7 : 4 ), $videos );
    }


    /**
     * Builds the output settings.
     *
     * @param array<string, mixed> $options Generation options
     * @param bool $ratio Whether the aspect ratio is used, which is defined by the first frame otherwise
     * @return array<string, mixed> Output settings
     */
    protected function settings( array $options, bool $ratio ) : array
    {
        $settings = [];

        if( is_int( $options['duration'] ?? null ) ) {
            $settings['duration'] = max( 3, min( 15, $options['duration'] ) );
        }

        if( in_array( $options['resolution'] ?? null, ['720p', '1080p', '4k'], true ) ) {
            $settings['resolution'] = $options['resolution'];
        }

        if( $ratio && in_array( $options['aspectRatio'] ?? null, ['16:9', '9:16', '1:1'], true ) ) {
            $settings['aspect_ratio'] = $options['aspectRatio'];
        }

        if( is_bool( $options['audio'] ?? null ) ) {
            $settings['audio'] = $options['audio'] ? 'native' : 'off';
        }

        if( is_bool( $options['multi_shot'] ?? null ) ) {
            $settings['multi_shot'] = $options['multi_shot'];
        }

        return $settings;
    }


    /**
     * Returns the public URL or the plain base64 encoded content of the file.
     *
     * @param File $file Input media file
     * @return string URL or base64 encoded content without data URI prefix
     */
    protected function source( File $file ) : string
    {
        return $file->url() ?: (string) $file->base64();
    }


    /**
     * Submits a Kling video task.
     *
     * @param string $path API endpoint path including the model name
     * @param array<string, mixed> $request Request payload
     * @return FileResponse Deferred video response
     */
    protected function submit( string $path, array $request ) : FileResponse
    {
        $response = $this->client()->post( $path, [
            'json' => array_filter( $request ),
        ] );
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $id = $data['data']['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed( $data['message'] ?? null );
        }

        return $this->resume( $id );
    }


    /**
     * Validates the HTTP response and throws for status codes outside the 2xx range.
     *
     * Kling reports exhausted resource packs and accounts in arrears with HTTP status 429, which
     * would be retried like rate limits otherwise, so they are reported as payment required.
     *
     * @param ResponseInterface $response HTTP response
     * @throws PrismaException If the request failed
     */
    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 429 ) {
            parent::validate( $response );
            return;
        }

        $data = $this->errorData( $response );
        $status = in_array( $data['code'] ?? null, [1101, 1102], true ) ? 402 : 429;

        $this->throw( $status, $this->errorMessage( $data ) ?? $response->getReasonPhrase(), $response );
    }
}
