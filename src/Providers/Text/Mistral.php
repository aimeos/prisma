<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Mistral as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Mistral extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() )
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $messages = [];

        if( $system = $this->systemPrompt() ) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $content];

        $params = [
            'model' => $this->modelName( 'mistral-large-latest' ),
            'messages' => $messages,
        ] + $this->allowed( $options, ['temperature', 'max_tokens', 'top_p'] );

        $response = $this->client()->post( 'v1/chat/completions', ['json' => $params] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['choices'] ?? [] as $data )
        {
            if( $text = $data['message']['content'] ?? null ) {
                $texts[] = $text;
            }
        }

        $meta = $result;
        unset( $meta['choices'], $meta['usage'] );

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                $result['usage']['total_tokens'] ?? null,
                $result['usage'] ?? [],
            )
            ->withMeta( $meta );
    }
}
