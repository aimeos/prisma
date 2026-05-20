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
    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'amazon.nova-canvas-v1:0' );
        $allowed = $this->allowed( $options, ['quality', 'height', 'width', 'cfgScale', 'seed'] );

        $request = [
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => [
                'text' => $prompt,
                ...$this->allowed( $options, ['controlMode', 'controlStrength', 'negativeText'] ) + ['controlStrength' => 0.25]
            ],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
            ] + $allowed
        ];

        if( $image = current( $images ) ) {
            $request['textToImageParams']['conditionImage'] = $image->base64();
        }

        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'amazon.nova-canvas-v1:0' );
        $allowed = $this->allowed( $options, ['quality', 'height', 'width', 'cfgScale'] );

        $request = [
            'taskType' => 'INPAINTING',
            'inPaintingParams' => [
                'image' => $image->base64(),
                'maskImage' => $this->invert( $mask )->base64(),
                'text' => $prompt,
                ...$this->allowed( $options, ['negativeText'] )
            ],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
            ] + $allowed
        ];
        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'amazon.nova-canvas-v1:0' );

        $request = [
            'taskType' => 'BACKGROUND_REMOVAL',
            'backgroundRemovalParams' => [
                'image' => $image->base64(),
            ],
        ];

        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/invoke', ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $promises = $vectors = [];
        $model = $this->modelName( 'amazon.titan-embed-image-v1' );

        foreach( $images as $index => $image )
        {
            $promises[$index] = $this->client()->postAsync( 'model/' . $model . '/invoke', [
                'json' => [
                    "inputImage" => $image->base64(),
                    "embeddingConfig" => [
                        "outputEmbeddingLength" => $size ?? 1024
                    ]
                ],
            ] );
        }

        foreach( $promises as $index => $promise )
        {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = $promise->wait();
            $this->validate( $response );

            /** @var array<int, float> $embedding */
            $embedding = @$this->fromJson( $response )['embedding'] ?? [];
            $vectors[$index] = $embedding;
        }

        /** @var array<int, array<int, float>|null> $vectors */
        return VectorResponse::fromVectors( $vectors );
    }


    protected function invert( Image $image ) : Image
    {
        if( ( $img = imagecreatefromstring( (string) $image->binary() ) ) === false ) {
            throw new PrismaException( "Invalid image/mask data" );
        }

        if( ( $stream = fopen( 'php://memory', 'r+' ) ) === false ) {
            throw new PrismaException( "Unable to create image stream" );
        }

        imagefilter( $img, IMG_FILTER_NEGATE );

        imagepng( $img, $stream );
        rewind( $stream );

        $png = stream_get_contents( $stream );

        fclose( $stream );

        return Image::fromBinary( $png, 'image/png' );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $files = [];

        /** @var array<int, string> $images */
        $images = $data['images'] ?? [];

        foreach( $images as $image ) {
            $files[] = Image::fromBase64( $image, 'image/png' );
        }

        if( empty( $files ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        return FileResponse::fromFiles( $files );
    }


}
