<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Image;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Files\Image as ImageFile;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Openai extends Base implements Image, Inpaint
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'No API key' ) );
        }

        $this->header( 'OpenAI-Organization', $config['organization'] ?? null );
        $this->header( 'OpenAI-Project', $config['project'] ?? null );
        $this->header( 'authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( 'https://api.openai.com' );
    }


    public function image( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $request = $this->request( $this->params( $prompt, $options ) );
        $response = $this->client()->post( 'v1/images/generations', $request );

        return $this->toFileResponse( $response );
    }


    public function inpaint( ImageFile $image, string $prompt, ?ImageFile $mask = null, array $options = [] ) : FileResponse
    {
        $request = $this->request( $this->params( $prompt, $options ), ['image' => [$image], 'mask' => $mask] );
        $response = $this->client()->post( 'v1/images/edits', $request );

        return $this->toFileResponse( $response );
    }


    protected function params( string $prompt, array $options ) : array
    {
        $model = $this->modelName( 'dall-e-3' );
        $data = ['model' => $model, 'prompt' => $prompt, 'response_format' => 'b64_json'];

        $names = match( $model ) {
            'gpt-image-1' => ['background', 'moderation', 'output_compression', 'output_format'],
            'dall-e-3' => ['style'],
            default => [],
        };

        $allowed = $this->allowed( $options, $names + ['quality', 'size', 'user'] );
        $allowed = $this->sanitize( $allowed, [
            'background' => match( $model ) {
                'gpt-image-1' => ['transparent', 'opaque', 'auto'],
                default => [],
            },
            'moderation' => match( $model ) {
                'gpt-image-1' => ['low', 'auto'],
                default => [],
            },
            'output_format' => match( $model ) {
                'gpt-image-1' => ['png', 'jpeg', 'webp'],
                default => [],
            },
            'quality' => match( $model ) {
                'gpt-image-1' => ['low', 'medium', 'high', 'auto'],
                'dall-e-3' => ['standard', 'hd'],
                'dall-e-2' => ['standard'],
                default => [],
            },
            'size' => match( $model ) {
                'gpt-image-1' => ['1536x1024', '1024x1536', '1024x1024', 'auto'],
                'dall-e-3' => ['1792x1024', '1024x1792', '1024x1024', 'auto'],
                'dall-e-2' => ['1024x1024', '512x512', '256x256', 'auto'],
                default => ['auto'],
            },
            'style' => match( $model ) {
                'dall-e-3' => ['vivid', 'natural'],
                default => [],
            },
        ] );

        return $data + $allowed;
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        $result = json_decode( $response->getBody()->getContents(), true ) ?? [];

        if( !isset( $result['data']['b64_json'] ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        $meta = $result;
        unset( $meta['data'], $meta['usage'] );

        return FileResponse::fromBase64( $result['data']['b64_json'] )->withMeta( $meta )->withUsage(
            $result['usage']['total_tokens'] ?? null,
            $result['usage'] ?? [],
        );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->error?->message ?? $response->getReasonPhrase();

            switch( $response->getStatusCode() )
            {
                case 401: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
                case 403: throw new \Aimeos\Prisma\Exceptions\ForbiddenException( $error );
                case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
                case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
                default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
            }
        }
    }
}
