<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Veo extends Base implements Imagine, Resume
{
    use GeneratesVideo;


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'veo-3.1-generate-preview' );
        $response = $this->client()->post( 'v1beta/models/' . $model . ':predictLongRunning', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $name = $data['name'] ?? null;

        if( !is_string( $name ) || $name === '' ) {
            $this->videoFailed( $data['error']['message'] ?? null );
        }

        return $this->resume( $name );
    }


    public function resume( string $jobId ) : FileResponse
    {
        return FileResponse::fromAsync( $this->poll( $jobId ), 10, jobId: $jobId );
    }


    /**
     * Builds an inline Veo image entry.
     *
     * @param Image $image Input image
     * @return array{inlineData: array{mimeType: string, data: string}} Inline image entry
     */
    protected function image( Image $image ) : array
    {
        return ['inlineData' => [
            'mimeType' => $image->mimeType() ?? 'image/png',
            'data' => (string) $image->base64(),
        ]];
    }


    /**
     * Builds a Veo generation instance with supported media.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<string, mixed> Generation instance
     */
    protected function instance( string $prompt, array $media ) : array
    {
        $instance = ['prompt' => $prompt];
        $start = $media['start'] ?? null;
        $end = $media['end'] ?? null;
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];

        // Veo rejects reference images combined with frame interpolation, so frames take precedence.
        if( $start instanceof Image ) {
            $instance['image'] = $this->image( $start );

            if( $end instanceof Image ) {
                $instance['lastFrame'] = $this->image( $end );
            }

            return $instance;
        }

        foreach( $references as $reference ) {
            if( $reference instanceof Image && count( $instance['referenceImages'] ?? [] ) < 3 ) {
                $instance['referenceImages'][] = [
                    'image' => $this->image( $reference ),
                    'referenceType' => 'asset',
                ];
            }
        }

        return $instance;
    }


    /**
     * Maps common options to Veo generation parameters.
     *
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Veo parameters
     */
    protected function parameters( array $options ) : array
    {
        $parameters = [];
        $map = [
            'aspectRatio' => 'aspectRatio',
            'resolution' => 'resolution',
            'duration' => 'durationSeconds',
            'count' => 'sampleCount',
            'seed' => 'seed',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $parameters[$target] = $options[$source];
            }
        }

        return $parameters;
    }


    /**
     * Returns a closure that polls a Veo operation.
     *
     * @param string $name Operation name
     * @return \Closure Polling closure that populates the file response
     * @throws BadRequestException If the name isn't an operation name
     */
    protected function poll( string $name ) : \Closure
    {
        // Operation names only, they can't be dot segments or add queries to reach other endpoints
        if( !preg_match( '#^(models/\w[\w.-]*/)?operations/\w[\w.-]*$#D', $name ) ) {
            throw new BadRequestException( 'Invalid Veo operation name' );
        }

        return function( FileResponse $result ) use ( $name ) : bool {
            $response = $this->client()->get( 'v1beta/' . $name );
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $result->withMeta( ['operation' => $name] + $data );

            if( isset( $data['error'] ) ) {
                $this->videoFailed( $data['error']['message'] ?? null );
            }

            if( ( $data['done'] ?? false ) !== true ) {
                return false;
            }

            /** @var array<string, mixed> $operation */
            $operation = is_array( $data['response'] ?? null ) ? $data['response'] : [];
            /** @var array<string, mixed> $generated */
            $generated = is_array( $operation['generateVideoResponse'] ?? null ) ? $operation['generateVideoResponse'] : [];

            foreach( is_array( $generated['generatedSamples'] ?? null ) ? $generated['generatedSamples'] : [] as $sample ) {
                $url = is_array( $sample ) && is_array( $sample['video'] ?? null ) ? ( $sample['video']['uri'] ?? null ) : null;

                if( is_string( $url ) && $url !== '' ) {
                    $result->add( Video::fromUrl( $url, 'video/mp4' ) );
                }
            }

            if( $result->empty() ) {
                $this->videoFailed();
            }

            return true;
        };
    }


    /**
     * Builds the Veo long-running prediction request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $request = ['instances' => [$this->instance( $prompt, $media )]];
        $parameters = $this->parameters( $options );

        if( !empty( $parameters ) ) {
            $request['parameters'] = $parameters;
        }

        return $request;
    }
}
