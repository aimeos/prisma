<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Groq as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Groq extends Base implements Describe
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $response = $this->client()->post( 'openai/v1/responses', ['json' => [
            'model' => $this->modelName( 'qwen/qwen3.6-27b' ),
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
            /** @var array<int, array<string, mixed>> $content */
            $content = $data['content'] ?? [];

            foreach( $content as $part )
            {
                if( is_string( $part['text'] ?? null ) ) {
                    $texts[] = $part['text'];
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
}
