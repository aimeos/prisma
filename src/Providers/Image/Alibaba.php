<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Alibaba extends Base implements Imagine, Vectorize
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $config['url'] ?? 'https://dashscope-intl.aliyuncs.com' );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'qwen-image-2.0-pro' );

        $wan = str_starts_with( (string) $model, 'wan' );
        $zimg = str_starts_with( (string) $model, 'z-image' );
        $qimg2 = str_starts_with( (string) $model, 'qwen-image-2.0' );

        $names = ['prompt_extend', 'seed', 'size'];

        if( !$zimg ) {
            $names = array_merge( $names, ['negative_prompt', 'n', 'watermark'] );
        }

        $allowed = $this->allowed( $options, $names );
        $allowed = $this->sanitize( $allowed, [
            'n' => $qimg2 ? [1, 2, 3, 4, 5, 6] : ( $wan ? [1, 2, 3, 4] : [1] ),
            'size' => $qimg2 || $wan || $zimg ? null : ['1664*928', '1472*1104', '1328*1328', '1104*1472', '928*1664'],
        ] );

        $content = [['text' => $prompt]];

        foreach( $images as $image ) {
            $content[] = ['image' => $image->url() ?: 'data:' . ( $image->mimeType() ?? 'image/png' ) . ';base64,' . $image->base64()];
        }

        $request = [
            'model' => $model,
            'input' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => $content
                ]]
            ]
        ];

        if( !empty( $allowed ) ) {
            $request['parameters'] = $allowed;
        }

        $response = $this->client()->post( 'api/v1/services/aigc/multimodal-generation/generation', ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $allowed = $this->allowed( $options, ['output_type', 'instruct'] );

        $contents = [];

        foreach( $images as $image ) {
            $contents[] = ['image' => $image->url() ?: 'data:' . ( $image->mimeType() ?? 'image/png' ) . ';base64,' . $image->base64()];
        }

        $request = [
            'model' => $this->modelName( 'tongyi-embedding-vision-plus' ),
            'input' => [
                'contents' => $contents
            ]
        ];

        if( $size ) {
            $allowed['dimension'] = $size;
        }

        if( !empty( $allowed ) ) {
            $request['parameters'] = $allowed;
        }

        $response = $this->client()->post( 'api/v1/services/embeddings/multimodal-embedding/multimodal-embedding', ['json' => $request] );

        $this->validate( $response );

        $data = $this->fromJson( $response );

        $vectors = array_map( fn( $item ) => $item['embedding'], $data['output']['embeddings'] ?? [] );

        return VectorResponse::fromVectors( $vectors )
            ->withUsage( $data['usage']['image_tokens'] ?? 0, $data['usage'] ?? [] )
            ->withMeta( ['request_id' => $data['request_id'] ?? ''] );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $files = [];

        foreach( $data['output']['choices'] ?? [] as $choice )
        {
            $files = array_merge( $files, array_filter(
                array_map( function( $part ) {
                    return !empty( $part['image']) ? Image::fromUrl( $part['image'] ) : null;
                }, $choice['message']['content'] ?? [] )
            ) );
        }

        if( empty( $files ) ) {
            throw new PrismaException( 'No image data found in response' );
        }

        unset( $data['output'] );

        return FileResponse::fromFiles( $files )->withMeta( $data );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = $this->fromJson( $response )['message'] ?? $response->getReasonPhrase();

        $this->throw( $response->getStatusCode(), $error );
    }
}
