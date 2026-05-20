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

        $messages = [[
            'role' => 'user',
            'content' => $content
        ]];

        return $this->responses(
            'v1/responses', 'gpt-5.5', $messages, $options,
            ['temperature', 'max_output_tokens', 'top_p', 'store']
        );
    }
}
