<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
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


class Bedrock extends Base implements Describe, Imagine
{
    use GeneratesVideo;


    protected string $s3Uri = '';


    public function __construct( array $config )
    {
        parent::__construct( $config );
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
                    'text' => 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
                        . ( $lang ?? 'en' ) . '".',
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
