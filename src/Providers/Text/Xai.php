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

            if( $thinkingBudget = $this->thinkingBudget() ) {
                $options['reasoning'] = ['effort' => match( true ) {
                    $thinkingBudget <= 1024 => 'low',
                    $thinkingBudget <= 8192 => 'medium',
                    default => 'high',
                }];
            }

            return $this->responses(
                'v1/responses', 'grok-3', [['role' => 'user', 'content' => $content]], $options,
                ['temperature', 'top_p', 'reasoning']
            );
        }

        return $this->completions(
            'v1/chat/completions', 'grok-3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty']
        );
    }
}
