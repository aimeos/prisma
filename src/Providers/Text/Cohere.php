<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Cohere as CohereBase;
use Aimeos\Prisma\Responses\TextResponse;


class Cohere extends CohereBase implements Write
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
            'model' => $this->modelName( 'command-a-vision-07-2025' ),
            'messages' => $messages,
        ] + $this->allowed( $options, ['temperature', 'max_tokens', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty'] );

        $response = $this->client()->post( 'v2/chat', ['json' => $params] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['message']['content'] ?? [] as $block )
        {
            if( $text = $block['text'] ?? null ) {
                $texts[] = $text;
            }
        }

        $meta = $result;
        unset( $meta['message'], $meta['usage'] );

        $usage = $result['usage']['tokens'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                ( $usage['input_tokens'] ?? 0 ) + ( $usage['output_tokens'] ?? 0 ),
                $usage,
            )
            ->withMeta( $meta );
    }


}
