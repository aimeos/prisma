<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Vertexai extends Base implements Imagine, Inpaint, Upscale, Vectorize
{
    private string $projectid;
    private string $region;


    public function __construct( array $config )
    {
        if( !isset( $config['access_token'] ) ) {
            throw new PrismaException( sprintf( 'No access token' ) );
        }

        if( !isset( $config['project_id'] ) ) {
            throw new PrismaException( sprintf( 'No Google project ID' ) );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'access_token' ) );
        $region = $this->config( $config, 'region' );
        $this->baseUrl( 'https://' . ( $region !== '' ? $region . '-' : '' ) . 'aiplatform.googleapis.com' );

        $this->region = $region !== '' ? $region : 'global';
        $this->projectid = $this->config( $config, 'project_id' );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'imagen-4.0-generate-001' );
        $allowed = $this->allowed( $options, [
            'addWatermark', 'aspectRatio', 'enhancePrompt', 'language', 'negativePrompt', 'outputOptions',
            'personGeneration', 'safetySetting', 'sampleImageSize', 'seed', 'storageUri'
        ] );

        $instance = ['prompt' => $prompt];

        /* Results in "Request contains an invalid argument" error
        if( !empty( $images ) )
        {
            $model = 'imagen-3.0-capability-001';
            $instance['referenceImages'] = array_map( fn( Image $image ) => [
                'referenceType' => 'REFERENCE_TYPE_STYLE',
                'referenceId' => 1,
                'referenceImage' => [
                    'bytesBase64Encoded' => $image->base64()
                ]
            ], $images );
        }
        */

        $request = [
            'instances' => [$instance],
            'parameters' => ['sampleCount' => 1] + $allowed
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'imagen-3.0-capability-001' );
        $allowed = $this->allowed( $options, [
            'addWatermark', 'baseSteps', 'editMode', 'guidanceScale', 'includeRaiReason', 'includeSafetyAttributes',
            'language', 'negativePrompt', 'outputOptions', 'personGeneration', 'safetySetting', 'seed', 'storageUri'
        ] );

        $request = [
            'instances' => [[
                    'prompt' => $prompt,
                    'referenceImages' => [[
                        'referenceType' => 'REFERENCE_TYPE_RAW',
                        'referenceId' => 1,
                        'referenceImage' => [
                            'bytesBase64Encoded' => $image->base64()
                        ]
                    ], [
                        'referenceType' => 'REFERENCE_TYPE_MASK',
                        'referenceId' => 2,
                        'referenceImage' => [
                            'bytesBase64Encoded' => $mask->base64()
                        ],
                        'maskImageConfig' => [
                            'maskMode' => 'MASK_MODE_USER_PROVIDED'
                        ]
                    ],
                ]
            ]],
            'parameters' => ['sampleCount' => 1] + $allowed
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'imagen-4.0-upscale-preview' );
        $allowed = $this->allowed( $options, ['addWatermark', 'outputOptions', 'storageUri'] );

        $request = [
            'instances' => [[
                'prompt' => '',
                'image' => [
                    'bytesBase64Encoded' => $image->base64()
                ]
            ]],
            'parameters' => [
                'sampleCount' => 1,
                'mode' => 'upscale',
                ...$allowed,
                'upscaleConfig' => [
                    'upscaleFactor' => 'x' . max( 2, min( 4, $factor ) )
                ]
            ]
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $model = $this->modelName( 'multimodalembedding@001' );
        $request = [
            'instances' => [
                ...array_map( fn( Image $image ) => [
                    'image' => [
                        'bytesBase64Encoded' => $image->base64(),
                        'mimeType' => $image->mimeType()
                    ]
                ], $images ),
            ],
            'parameters' => [
                'dimension' => $size ?? 512
            ]
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> $predictions */
        $predictions = $data['predictions'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = array_map( fn( $entry ) => $entry['imageEmbedding'] ?? [], $predictions );

        return VectorResponse::fromVectors( $vectors )
            ->withMeta( ['deployedModelId' => $data['deployedModelId'] ?? null] );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $files = [];

        /** @var array<int, array<string, mixed>> $predictions */
        $predictions = $data['predictions'] ?? [];

        foreach( $predictions as $prediction )
        {
            if( !empty( $prediction['bytesBase64Encoded'] ) ) {
                /** @var string $b64 */
                $b64 = $prediction['bytesBase64Encoded'];
                /** @var string|null $mimeType */
                $mimeType = $prediction['mimeType'] ?? null;
                $files[] = Image::fromBase64( $b64, $mimeType );
            }
        }

        if( empty( $files ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        /** @var array<int, array<string, mixed>> $predsArr */
        $predsArr = $data['predictions'] ?? [];
        /** @var array<string, mixed> $first */
        $first = current( $predsArr ) ?: [];

        /** @var string|null $prompt */
        $prompt = $first['prompt'] ?? null;

        return FileResponse::fromFiles( $files )
            ->withDescription( $prompt );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            /** @var array<string, mixed> $errorObj */
            $errorObj = @$this->fromJson( $response )['error'] ?? [];
            $error = $errorObj['message'] ?? $response->getReasonPhrase();
            $this->throw( match( $status ) {
                403 => 401,
                404 => 400,
                default => $status,
            }, is_string( $error ) ? $error : '' );
        }
    }
}
