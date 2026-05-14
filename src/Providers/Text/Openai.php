<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Openai extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [['type' => 'input_text', 'text' => $prompt]];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() )
            ];
        }

        $params = [
            'model' => $this->modelName( 'gpt-5' ),
            'input' => [[
                'role' => 'user',
                'content' => $content
            ]]
        ] + $this->allowed( $options, ['temperature', 'max_output_tokens', 'top_p', 'store'] );

        if( $prompt = $this->systemPrompt() ) {
            $params['instructions'] = $prompt;
        }

        $response = $this->client()->post( 'v1/responses', ['json' => $params] );

        $this->validate( $response );

        $result = $this->fromJson( $response );
        $texts = [];

        foreach( $result['output'] ?? [] as $data )
        {
            foreach( $data['content'] ?? [] as $content )
            {
                if( $text = $content['text'] ?? null ) {
                    $texts[] = $text;
                }
            }
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                $result['usage']['total_tokens'] ?? null,
                $result['usage'] ?? [],
            )
            ->withMeta( $meta );
    }
}
