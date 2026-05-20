<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Xai as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Xai extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        if( $this->providerTools() )
        {
            $content = [['type' => 'input_text', 'text' => $prompt]];

            foreach( $files as $file )
            {
                $content[] = [
                    'type' => 'input_image',
                    'image_url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() )
                ];
            }

            return $this->responses(
                'v1/responses', 'grok-4.3', [['role' => 'user', 'content' => $content]], $options,
                ['temperature', 'max_output_tokens', 'top_p']
            );
        }

        return $this->completions(
            'v1/chat/completions', 'grok-4.3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty']
        );
    }
}
