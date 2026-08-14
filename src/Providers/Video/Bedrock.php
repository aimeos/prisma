<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Bedrock as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Bedrock extends Base implements Imagine
{
    use GeneratesVideo;


    protected string $s3Uri;


    public function __construct( array $config )
    {
        parent::__construct( $config );

        if( !( $this->s3Uri = $this->config( $config, 's3_uri' ) ) ) {
            throw new PrismaException( 'No S3 output URI' );
        }
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( $this->baseUrl . '/async-invoke', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validateVideoResponse( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $arn = $data['invocationArn'] ?? null;

        if( !is_string( $arn ) || $arn === '' ) {
            $this->videoFailed( is_string( $data['message'] ?? null ) ? $data['message'] : null );
        }

        return FileResponse::fromAsync( $this->poll( $arn ), 10 );
    }


    /**
     * Returns the Nova Reel image format identifier.
     *
     * @param Image $image Start frame
     * @return string Image format identifier
     */
    protected function imageFormat( Image $image ) : string
    {
        return in_array( $image->mimeType(), ['image/jpeg', 'image/jpg'], true ) ? 'jpeg' : 'png';
    }


    /**
     * Returns a closure that polls a Bedrock async invocation.
     *
     * @param string $arn Invocation ARN
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $arn ) : \Closure
    {
        return function( FileResponse $result ) use ( $arn ) : bool {
            $response = $this->client()->get( $this->baseUrl . '/async-invoke/' . rawurlencode( $arn ) );
            $this->validateVideoResponse( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $status = $data['status'] ?? null;

            if( $status === 'Failed' ) {
                $this->videoFailed( is_string( $data['failureMessage'] ?? null ) ? $data['failureMessage'] : null );
            }

            if( $status !== 'Completed' ) {
                return false;
            }

            /** @var array<string, mixed> $output */
            $output = is_array( $data['outputDataConfig'] ?? null ) ? $data['outputDataConfig'] : [];
            /** @var array<string, mixed> $s3 */
            $s3 = is_array( $output['s3OutputDataConfig'] ?? null ) ? $output['s3OutputDataConfig'] : [];
            $uri = $s3['s3Uri'] ?? $this->s3Uri;

            if( !is_string( $uri ) || $uri === '' ) {
                $this->videoFailed();
            }

            $result->add( Video::fromUrl( rtrim( $uri, '/' ) . '/output.mp4', 'video/mp4' ) )->withMeta( $data );
            return true;
        };
    }


    /**
     * Builds the Bedrock Nova Reel invocation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $start = $media['start'] ?? null;
        $duration = is_int( $options['duration'] ?? null ) ? $options['duration'] : 6;
        $multi = !( $start instanceof Image ) && $duration >= 12 && $duration <= 120 && $duration % 6 === 0;
        $params = ['text' => $prompt];

        if( $start instanceof Image ) {
            $params['images'] = [[
                'format' => $this->imageFormat( $start ),
                'source' => ['bytes' => $start->base64()],
            ]];
        }

        $config = [
            'durationSeconds' => $multi ? $duration : 6,
            'fps' => 24,
            'dimension' => '1280x720',
        ];

        if( is_int( $options['seed'] ?? null ) ) {
            $config['seed'] = $options['seed'];
        }

        return [
            'modelId' => $this->modelName( 'amazon.nova-reel-v1:1' ),
            'modelInput' => [
                'taskType' => $multi ? 'MULTI_SHOT_AUTOMATED' : 'TEXT_VIDEO',
                $multi ? 'multiShotAutomatedParams' : 'textToVideoParams' => $params,
                'videoGenerationConfig' => $config,
            ],
            'outputDataConfig' => [
                's3OutputDataConfig' => ['s3Uri' => $this->s3Uri],
            ],
        ];
    }
}
