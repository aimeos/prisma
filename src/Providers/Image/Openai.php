<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Openai extends Base implements Describe, Imagine, Inpaint
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $response = $this->client()->post( 'v1/responses', ['json' => [
            'model' => $this->modelName( 'gpt-5.5' ),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Summarize the content of the file in a few words in plain text format in the language of ISO code "' . ($lang ?? 'en') . '".'
                ], [
                    'type' => 'input_image',
                    'image_url' => $image->url() ?? sprintf( 'data:%s;base64,%s', $image->mimeType(), $image->base64() )
                ]]
            ]]
        ]] );

        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        /** @var array<string|null> $texts */
        $texts = [];

        /** @var array<int, array<string, mixed>> $output */
        $output = $result['output'] ?? [];

        foreach( $output as $data )
        {
            /** @var array<int, array<string, mixed>> $contentItems */
            $contentItems = $data['content'] ?? [];

            foreach( $contentItems as $content )
            {
                if( $text = $content['text'] ?? null ) {
                    /** @var string $text */
                    $texts[] = $text;
                }
            }
        }

        if( empty( $texts ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No text found in response' );
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];
        $used = $usage['total_tokens'] ?? null;

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                is_numeric( $used ) ? (float) $used : null,
                $usage,
            )
            ->withMeta( $meta );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'v1/images/generations', ['json' => $this->params( $prompt, $options )] );

        return $this->toFileResponse( $response );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $params = $this->params( $prompt, $options, 'gpt-image-1' );
        $request = $this->payload( $params, ['image' => $image, 'mask' => $this->mask( $mask )] );
        $response = $this->client()->post( 'v1/images/edits', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    protected function mask( Image $image ) : Image
    {
        if( ( $mask = imagecreatefromstring( (string) $image->binary() ) ) === false ) {
            throw new PrismaException( 'Invalid image/mask data' );
        }

        if( ( $stream = fopen( 'php://memory', 'r+' ) ) === false ) {
            throw new PrismaException( 'Unable to create image stream' );
        }

        imagesavealpha( $mask, true );
        imagealphablending( $mask, false );

        $width = imagesx( $mask );
        $height = imagesy( $mask );

        if( ( $transparent = imagecolorallocatealpha( $mask, 255, 255, 255, 127 ) ) === false ) {
            throw new PrismaException( 'Unable to allocate transparent color' );
        }

        for( $y = 0; $y < $height; $y++ )
        {
            for( $x = 0; $x < $width; $x++ )
            {
                if( ( imagecolorat( $mask, $x, $y ) & 0xFF ) === 0xFF ) { // white pixel
                    imagesetpixel( $mask, $x, $y, $transparent );
                }
            }
        }

        imagepng( $mask, $stream );
        rewind( $stream );

        $png = stream_get_contents( $stream );

        fclose( $stream );

        return Image::fromBinary( $png, 'image/png' );
    }


    /**
     * Builds the parameters for the image requests.
     *
     * @param string $prompt Prompt describing the image
     * @param array<string, mixed> $options Provider specific options
     * @param string|null $model Optional model name
     * @return array<string, mixed>
     */
    protected function params( string $prompt, array $options, ?string $model = null ) : array
    {
        $model = $this->modelName( $model ?? 'gpt-image-1' );
        $data = ['model' => $model, 'prompt' => $prompt];

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


    /**
     * Converts the Guzzle response into a file response.
     *
     * @param ResponseInterface $response Guzzle HTTP response
     * @return FileResponse File based response
     */
    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        $files = [];

        /** @var array<int, array<string, mixed>> $dataItems */
        $dataItems = $result['data'] ?? [];

        foreach( $dataItems as $item )
        {
            if( !empty( $item['b64_json'] ) ) {
                /** @var string $b64 */
                $b64 = $item['b64_json'];
                $files[] = Image::fromBase64( $b64 );
            } elseif( !empty( $item['url'] ) ) {
                /** @var string $url */
                $url = $item['url'];
                $files[] = Image::fromUrl( $url );
            }
        }

        if( empty( $files ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        $meta = $result;
        unset( $meta['data'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];
        $used = $usage['total_tokens'] ?? null;

        return FileResponse::fromFiles( $files )
            ->withUsage(
                is_numeric( $used ) ? (float) $used : null,
                $usage,
            )
            ->withMeta( $meta );
    }
}
