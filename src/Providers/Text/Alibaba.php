<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Alibaba extends Base implements Write
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://dashscope-intl.aliyuncs.com' ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType() ?? 'image/png', $file->base64() )
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $messages = [];

        if( $system = $this->systemPrompt() ) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $content];

        $request = [
            'model' => $this->modelName( 'qwen-vl-plus' ),
            'messages' => $messages,
        ] + $this->allowed( $options, ['temperature', 'max_tokens', 'top_p', 'top_k'] );

        $response = $this->client()->post( 'compatible-mode/v1/chat/completions', ['json' => $request] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $texts = [];

        foreach( $data['choices'] ?? [] as $choice )
        {
            if( $text = $choice['message']['content'] ?? null ) {
                $texts[] = $text;
            }
        }

        $meta = $data;
        unset( $meta['choices'], $meta['usage'] );

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                $data['usage']['total_tokens'] ?? null,
                $data['usage'] ?? [],
            )
            ->withMeta( $meta );
    }

}
