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
            $options = $this->reasoning( $this->allowed( $options, ['temperature', 'top_p', 'reasoning'] ) );

            return $this->responses(
                'v1/responses', 'grok-3',
                [['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )]],
                $options
            );
        }

        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'v1/chat/completions', 'grok-3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }


    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function reasoning( array $options ) : array
    {
        if( $thinkingBudget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['effort' => match( true ) {
                $thinkingBudget <= 1024 => 'low',
                $thinkingBudget <= 8192 => 'medium',
                default => 'high',
            }];
        }

        return $options;
    }
}
