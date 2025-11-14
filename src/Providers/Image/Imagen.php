<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Imagen extends Base implements Background, Imagine, Inpaint, Upscale
{
    private string $projectid;
    private string $region;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        if( !isset( $config['project_id'] ) ) {
            throw new PrismaException( sprintf( 'No Google project ID' ) );
        }

        $this->header( 'Authorization', 'Bearer: ' . $config['api_key'] );
        $this->baseUrl( 'https://' . ( isset( $config['region'] ) ? $config['region'] . '-' : '' ) . 'aiplatform.googleapis.com' );

        $this->region = $config['region'] ?? 'global';
        $this->projectid = $config['project_id'];
    }


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'imagen-product-recontext-preview-06-30' );
        $allowed = $this->allowed( $options, [
            'addWatermark', 'enhancePrompt', 'outputOptions', 'personGeneration', 'safetySetting', 'seed', 'storageUri'
        ] ) + ['sampleCount' => 1];

        $request = [
            'instances' => [[
                'prompt' => $prompt,
                'productImages' => [[
                    'image' => [
                        'bytesBase64Encoded' => $image->base64()
                    ]
                ]]
            ]],
            'parameters' => [
                ...$allowed
            ]
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'imagen-4.0-generate-001' );
        $allowed = $this->allowed( $options, [
            'addWatermark', 'aspectRatio', 'enhancePrompt', 'language', 'negativePrompt', 'outputOptions',
            'personGeneration', 'safetySetting', 'sampleImageSize', 'seed', 'storageUri'
        ] ) + ['sampleCount' => 1];

        $instance = ['prompt' => $prompt];

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

        $request = [
            'instances' => [$instance],
            'parameters' => [...$allowed]
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
        ] ) + ['sampleCount' => 1];

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
            'parameters' => [
                ...$allowed
            ]
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
                'image' => [
                    'bytesBase64Encoded' => $image->base64()
                ]
            ]],
            'parameters' => [
                ...$allowed,
                'upscaleConfig' => [
                    'upscaleFactor' => 'x' . min( 2, max( 4, $factor ) )
                ]
            ]
        ];
        $url = 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model . ':predict';
        $response = $this->client()->post( $url, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];
        $data = current( $data['predictions'] ?? [] ) ?: null;

        if( !$data ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        return FileResponse::fromBase64( $data['bytesBase64Encoded'], $data['mimeType'] )
            ->withDescription( $data['prompt'] ?? null );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = json_decode( $response->getBody()->getContents() )?->error?->message ?: $response->getReasonPhrase();

        switch( $response->getStatusCode() )
        {
            case 400:
            case 404: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
            case 401:
            case 403: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
            case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
        }
    }
}
