<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Anthropic as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Anthropic extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $file->mimeType(),
                    'data' => $file->base64()
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $params = [
            'model' => $this->modelName( 'claude-sonnet-4-20250514' ),
            'messages' => [[
                'role' => 'user',
                'content' => $content
            ]],
            'max_tokens' => $options['max_tokens'] ?? 4096,
        ] + $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        if( $system = $this->systemPrompt() ) {
            $params['system'] = $system;
        }

        $response = $this->client()->post( 'v1/messages', ['json' => $params] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['content'] ?? [] as $block )
        {
            if( ( $block['type'] ?? null ) === 'text' && isset( $block['text'] ) ) {
                $texts[] = $block['text'];
            }
        }

        $meta = $result;
        unset( $meta['content'], $meta['usage'] );

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                ( $result['usage']['input_tokens'] ?? 0 ) + ( $result['usage']['output_tokens'] ?? 0 ),
                $result['usage'] ?? [],
            )
            ->withMeta( $meta );
    }
}
