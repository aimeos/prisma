<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Bedrock as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends Base implements Describe, Imagine, Resume
{
    use GeneratesVideo;


    protected string $s3Uri = '';


    public function __construct( array $config )
    {
        parent::__construct( $config + ['url' => 'https://bedrock-runtime.us-west-2.amazonaws.com'] );
        $this->s3Uri = $this->config( $config, 's3_uri' );
    }


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'us.amazon.nova-lite-v1:0' );
        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', [
            'json' => $this->describeRequest( $video, $lang, $options ),
        ] );
        $this->validate( $response );

        return $this->textResponse( $response );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        if( !$this->s3Uri ) {
            throw new PrismaException( 'No S3 output URI' );
        }

        $response = $this->client()->post( $this->baseUrl . '/async-invoke', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $arn = $data['invocationArn'] ?? null;

        if( !is_string( $arn ) || $arn === '' ) {
            $this->videoFailed( $data['message'] ?? null );
        }

        return $this->resume( $arn );
    }


    public function resume( string $jobId ) : FileResponse
    {
        $jobId = $this->jobId( $jobId );

        return FileResponse::fromAsync( $this->poll( $jobId ), 10, jobId: $jobId );
    }


    /**
     * Builds an Amazon Nova video understanding request.
     *
     * @param Video $video Input video
     * @param string|null $lang ISO language code for the description
     * @param array<string, mixed> $options Provider specific options
     * @return array<string, mixed> Request payload
     */
    protected function describeRequest( Video $video, ?string $lang, array $options ) : array
    {
        $config = $this->allowed( $options, ['temperature', 'topP', 'topK'] );

        if( $this->maxTokens() ) {
            $config['maxTokens'] = $this->maxTokens();
        }

        $request = [
            'schemaVersion' => 'messages-v1',
            'messages' => [[
                'role' => 'user',
                'content' => [[
                    'video' => [
                        'format' => $this->videoFormat( $video ),
                        'source' => $this->videoSource( $video, $options ),
                    ],
                ], [
                    'text' => $this->summary( $lang ),
                ]],
            ]],
        ];

        if( $system = $this->systemPrompt() ) {
            $request['system'] = [['text' => $system]];
        }

        if( !empty( $config ) ) {
            $request['inferenceConfig'] = $config;
        }

        return $request;
    }


    /**
     * Returns the Amazon Nova identifier for the video's format.
     *
     * @param Video $video Input video
     * @return string Video format identifier
     */
    protected function videoFormat( Video $video ) : string
    {
        return match( $video->mimeType() ) {
            'video/x-matroska' => 'mkv',
            'video/quicktime' => 'mov',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/3gpp' => 'three_gp',
            'video/x-flv' => 'flv',
            'video/mpeg' => 'mpeg',
            'video/mpg' => 'mpg',
            'video/x-ms-wmv' => 'wmv',
            default => throw new BadRequestException( 'Unsupported Amazon Nova video format' ),
        };
    }


    /**
     * Builds the Amazon Nova video source.
     *
     * @param Video $video Input video
     * @param array<string, mixed> $options Provider specific options
     * @return array<string, mixed> Inline bytes or S3 location
     */
    protected function videoSource( Video $video, array $options ) : array
    {
        $url = $video->url();

        if( !$url || !str_starts_with( $url, 's3://' ) ) {
            return ['bytes' => $video->base64()];
        }

        $location = ['uri' => $url];

        if( is_string( $options['bucketOwner'] ?? null ) ) {
            $location['bucketOwner'] = $options['bucketOwner'];
        }

        return ['s3Location' => $location];
    }


    /**
     * Converts an Amazon Nova response into a text response.
     *
     * @param ResponseInterface $response Provider response
     * @return TextResponse Text response
     */
    protected function textResponse( ResponseInterface $response ) : TextResponse
    {
        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $output */
        $output = is_array( $data['output'] ?? null ) ? $data['output'] : [];
        /** @var array<string, mixed> $message */
        $message = is_array( $output['message'] ?? null ) ? $output['message'] : [];
        $texts = [];

        foreach( is_array( $message['content'] ?? null ) ? $message['content'] : [] as $content ) {
            if( is_array( $content ) && is_string( $content['text'] ?? null ) ) {
                $texts[] = $content['text'];
            }
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
        $used = ( is_numeric( $usage['inputTokens'] ?? null ) ? (float) $usage['inputTokens'] : 0 )
            + ( is_numeric( $usage['outputTokens'] ?? null ) ? (float) $usage['outputTokens'] : 0 );
        $meta = $data;
        unset( $meta['output'], $meta['usage'] );

        return TextResponse::fromTexts( $texts )
            ->withReason( match( $data['stopReason'] ?? null ) {
                'end_turn', 'stop_sequence' => TextResponse::STOP,
                'max_tokens' => TextResponse::LENGTH,
                'content_filtered' => TextResponse::CONTENT,
                default => TextResponse::UNKNOWN,
            } )
            ->withUsage( $used, $usage )
            ->withRateLimit( $this->getRateLimit( $response ) )
            ->withMeta( $meta );
    }


    /**
     * Builds a Luma Ray keyframe from an image.
     *
     * @param Image $image Start or end frame
     * @return array<string, mixed> Keyframe definition
     */
    protected function keyframe( Image $image ) : array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => in_array( $image->mimeType(), ['image/jpeg', 'image/jpg'], true ) ? 'image/jpeg' : 'image/png',
                'data' => $image->base64(),
            ],
        ];
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
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $result->withMeta( $data );
            $status = $data['status'] ?? null;

            if( $status === 'Failed' ) {
                $this->videoFailed( $data['failureMessage'] ?? null );
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

            $result->add( $this->urlFile( rtrim( $uri, '/' ) . '/output.mp4', Video::class, 'video/mp4' ) );
            return true;
        };
    }


    /**
     * Builds the Bedrock Luma Ray invocation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $input = ['prompt' => $prompt] + $this->sanitize( $this->allowed( $options, ['aspect_ratio', 'resolution'] ), [
            'aspect_ratio' => ['1:1', '16:9', '9:16', '4:3', '3:4', '21:9', '9:21'],
            'resolution' => ['540p', '720p'],
        ] );

        $input['duration'] = is_int( $options['duration'] ?? null ) && $options['duration'] > 5 ? '9s' : '5s';

        if( is_bool( $options['loop'] ?? null ) ) {
            $input['loop'] = $options['loop'];
        }

        foreach( ['start' => 'frame0', 'end' => 'frame1'] as $role => $frame )
        {
            if( ( $image = $media[$role] ?? null ) instanceof Image ) {
                $input['keyframes'][$frame] = $this->keyframe( $image );
            }
        }

        return [
            'modelId' => $this->modelName( 'luma.ray-v2:0' ),
            'modelInput' => $input,
            'outputDataConfig' => [
                's3OutputDataConfig' => ['s3Uri' => $this->s3Uri],
            ],
        ];
    }
}
