<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Recognize;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;


class Openrouter extends Base implements Describe, Imagine, Recognize, Repaint, Vectorize
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';

        return $this->understand( $image, $prompt, $options );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, [
            'background', 'n', 'output_compression', 'output_format', 'provider',
            'quality', 'resolution', 'seed', 'size', 'user'
        ] );

        if( isset( $options['aspectRatio'] ) ) {
            $allowed['aspect_ratio'] = $options['aspectRatio'];
        }

        if( $images ) {
            $allowed['input_references'] = array_map( fn( Image $image ) => [
                'type' => 'image_url',
                'image_url' => ['url' => $this->fileUrl( $image )],
            ], $images );
        }

        $request = [
            'model' => $this->modelName( 'openai/gpt-image-2' ),
            'prompt' => $prompt,
        ] + $allowed;
        $response = $this->client()->post( 'api/v1/images', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<int, array<string, mixed>> $items */
        $items = is_array( $data['data'] ?? null ) ? $data['data'] : [];
        $files = [];

        foreach( $items as $item )
        {
            $base64 = $item['b64_json'] ?? null;
            $mimeType = $item['media_type'] ?? null;

            if( is_string( $base64 ) && $base64 !== '' ) {
                $files[] = Image::fromBase64( $base64, is_string( $mimeType ) ? $mimeType : 'image/png' );
            }
        }

        if( !$files ) {
            throw new PrismaException( 'No image data found in response' );
        }

        /** @var array<string, mixed> $usage */
        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
        $used = $usage['total_tokens'] ?? null;
        $meta = $data;
        unset( $meta['data'], $meta['usage'] );

        return FileResponse::fromFiles( $files )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
            ->withMeta( $meta );
    }


    public function recognize( Image $image, array $options = [] ) : TextResponse
    {
        return $this->understand(
            $image,
            'Extract all visible text from the image. Return only the text in reading order.',
            $options
        );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->imagine( $prompt, [$image], $options );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $inputs = array_map( fn( Image $image ) => [
            'content' => [[
                'type' => 'image_url',
                'image_url' => ['url' => $this->fileUrl( $image )],
            ]],
        ], $images );
        $options = $this->allowed( $options, ['encoding_format', 'input_type', 'provider', 'user'] );

        return $this->embeddings(
            'api/v1/embeddings', 'voyageai/voyage-multimodal-3.5',
            $inputs, $size, $options
        );
    }


    /**
     * Sends an image understanding request.
     *
     * @param Image $image Input image
     * @param string $prompt Task prompt
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Text response
     */
    protected function understand( Image $image, string $prompt, array $options ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning', 'provider'] );

        return $this->completions(
            'api/v1/chat/completions', 'google/gemini-3.7-flash',
            $this->messages( $this->content( $prompt, [$image] ) ),
            $options
        );
    }
}
