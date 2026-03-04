<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Qwen extends Base implements Imagine
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
        $allowed = $this->allowed( $options, ['negative_prompt', 'n', 'prompt_extend', 'seed', 'size', 'watermark'] );

        $qimg2 = str_starts_with( (string) $model, 'qwen-image-2.0' );
        $allowed = $this->sanitize( $allowed, [
            'n' => $qimg2 ? [1, 2, 3, 4, 5, 6] : [1],
            'size' => !$qimg2 ? ['1664*928', '1472*1104', '1328*1328', '1104*1472', '928*1664'] : null,
        ] );

        $request = [
            'model' => $model,
            'input' => [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['text' => $prompt]]
                ]]
            ]
        ];

        if( !empty( $allowed ) ) {
            $request['parameters'] = $allowed;
        }

        $response = $this->client()->post( 'api/v1/services/aigc/multimodal-generation/generation', ['json' => $request] );

        return $this->toFileResponse( $response );
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
