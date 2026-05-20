<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Bedrock as BedrockBase;
use Aimeos\Prisma\Responses\TextResponse;


class Bedrock extends BedrockBase implements Write
{


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'image' => [
                    'format' => explode( '/', (string) $file->mimeType() )[1] ?? 'png',
                    'source' => [
                        'bytes' => $file->base64()
                    ]
                ]
            ];
        }

        $content[] = ['text' => $prompt];

        $messages = [['role' => 'user', 'content' => $content]];

        $request = [
            'messages' => $messages,
        ];

        if( $system = $this->systemPrompt() ) {
            $request['system'] = [['text' => $system]];
        }

        $config = $this->allowed( $options, ['temperature', 'maxTokens', 'topP'] );

        if( !empty( $config ) ) {
            $request['inferenceConfig'] = $config;
        }

        $model = $this->modelName( 'amazon.nova-pro-v1:0' );
        $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/converse', ['json' => $request] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['output']['message']['content'] ?? [] as $block )
        {
            if( $text = $block['text'] ?? null ) {
                $texts[] = $text;
            }
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        $usage = $result['usage'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                ( $usage['inputTokens'] ?? 0 ) + ( $usage['outputTokens'] ?? 0 ),
                $usage,
            )
            ->withMeta( $meta );
    }


}
