<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Bedrock as BedrockBase;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends BedrockBase implements Imagine, Inpaint, Isolate, Vectorize
{
    protected string $imagineUrl;


    public function __construct( array $config )
    {
        parent::__construct( $config );
        $this->imagineUrl = $this->config( $config, 'url', 'https://bedrock-runtime.us-west-2.amazonaws.com' );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'stability.sd3-5-large-v1:0' );
        $allowed = $this->allowed( $options, ['aspect_ratio', 'negative_prompt', 'output_format', 'seed', 'strength'] );
        $allowed = $this->sanitize( $allowed, [
            'aspect_ratio' => ['16:9', '1:1', '21:9', '2:3', '3:2', '4:5', '5:4', '9:16', '9:21'],
            'output_format' => ['png', 'jpeg', 'webp'],
        ] );

        $request = ['prompt' => $prompt] + $allowed;

        if( $image = current( $images ) )
        {
            unset( $request['aspect_ratio'] );
            $request = ['mode' => 'image-to-image', 'image' => $image->base64()] + $request + ['strength' => 0.85];
        }

        $response = $this->client()->post( $this->imagineUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response, $allowed );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'us.stability.stable-image-inpaint-v1:0' );
        $allowed = $this->allowed( $options, ['grow_mask', 'negative_prompt', 'output_format', 'seed', 'style_preset'] );
        $allowed = $this->sanitize( $allowed, ['output_format' => ['png', 'jpeg', 'webp']] );

        $request = [
            'image' => $image->base64(),
            'mask' => $mask->base64(),
            'prompt' => $prompt,
        ] + $allowed;

        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response, $allowed );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'us.stability.stable-image-remove-background-v1:0' );
        $allowed = $this->allowed( $options, ['output_format'] );
        $allowed = $this->sanitize( $allowed, ['output_format' => ['png', 'webp']] );

        $request = ['image' => $image->base64()] + $allowed;
        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response, $allowed );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $promises = $vectors = [];
        $model = (string) $this->modelName( 'amazon.nova-2-multimodal-embeddings-v1:0' );
        $nova = str_contains( $model, 'nova-2-multimodal-embeddings' );
        $allowed = $this->sanitize( $this->allowed( $options, ['embeddingPurpose', 'detailLevel'] ), [
            'embeddingPurpose' => ['GENERIC_INDEX', 'GENERIC_RETRIEVAL', 'TEXT_RETRIEVAL', 'IMAGE_RETRIEVAL',
                'VIDEO_RETRIEVAL', 'DOCUMENT_RETRIEVAL', 'AUDIO_RETRIEVAL', 'CLASSIFICATION', 'CLUSTERING'],
            'detailLevel' => ['STANDARD_IMAGE', 'DOCUMENT_IMAGE'],
        ] );

        foreach( $images as $index => $image )
        {
            $request = [
                'inputImage' => $image->base64(),
                'embeddingConfig' => [
                    'outputEmbeddingLength' => $size ?? 1024
                ]
            ];

            if( $nova )
            {
                $format = match( $image->mimeType() ) {
                    'image/jpeg', 'image/jpg' => 'jpeg',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'png',
                };

                $request = [
                    'schemaVersion' => 'nova-multimodal-embed-v1',
                    'taskType' => 'SINGLE_EMBEDDING',
                    'singleEmbeddingParams' => [
                        'embeddingPurpose' => $allowed['embeddingPurpose'] ?? 'GENERIC_INDEX',
                        'embeddingDimension' => $size ?? 3072,
                        'image' => [
                            'detailLevel' => $allowed['detailLevel'] ?? 'STANDARD_IMAGE',
                            'format' => $format,
                            'source' => ['bytes' => $image->base64()],
                        ],
                    ],
                ];
            }

            $promises[$index] = $this->client()->postAsync( $this->baseUrl . '/model/' . $model . '/invoke', [
                'json' => $request,
            ] );
        }

        foreach( $promises as $index => $promise )
        {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $promise->wait();
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            /** @var array<int, array{embedding?: array<int, float>}> $embeddings */
            $embeddings = $data['embeddings'] ?? [];
            /** @var array<int, float> $embedding */
            $embedding = $nova ? ( $embeddings[0]['embedding'] ?? [] ) : ( $data['embedding'] ?? [] );
            $vectors[$index] = $embedding;
        }

        /** @var array<int, array<int, float>|null> $vectors */
        return VectorResponse::fromVectors( $vectors );
    }


    /**
     * Converts a Stability response into a file response.
     *
     * @param ResponseInterface $response Provider response
     * @param array<string, mixed> $options Request options containing the optional output format
     * @return FileResponse File response
     */
    protected function toFileResponse( ResponseInterface $response, array $options = [] ) : FileResponse
    {
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $mime = 'image/' . ( is_string( $options['output_format'] ?? null ) ? $options['output_format'] : 'png' );
        $files = [];

        /** @var array<int, string> $images */
        $images = $data['images'] ?? [];

        foreach( $images as $image ) {
            $files[] = Image::fromBase64( $image, $mime );
        }

        if( empty( $files ) )
        {
            /** @var array<int, string|null> $reasons */
            $reasons = $data['finish_reasons'] ?? [];
            throw new PrismaException( current( array_filter( $reasons ) ) ?: 'No image data found in response' );
        }

        return FileResponse::fromFiles( $files );
    }
}
