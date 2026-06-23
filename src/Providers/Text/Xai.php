<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Xai as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Xai extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        if( $this->providerTools() )
        {
            $options = $this->reasoning( $this->allowed( $options, ['temperature', 'top_p', 'reasoning'] ) );

            return $this->responses(
                'v1/responses', 'grok-4.3',
                $this->responsesInput( $prompt, $files ),
                $options, $callback
            );
        }

        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'v1/chat/completions', 'grok-4.3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, $callback
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;

        if( $this->providerTools() )
        {
            $options = $this->reasoning( $this->allowed( $options, ['temperature', 'top_p', 'reasoning'] ) );

            return $this->structuredResponses(
                'v1/responses', 'grok-4.3',
                $prompt, $files, $schema, $options, $mode
            );
        }

        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->structuredCompletions(
            'v1/chat/completions', 'grok-4.3',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        if( $this->providerTools() )
        {
            $options = $this->reasoning( $this->allowed( $options, ['temperature', 'top_p', 'reasoning'] ) );

            return $this->responses(
                'v1/responses', 'grok-4.3',
                $this->responsesInput( $prompt, $files ),
                $options
            );
        }

        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'v1/chat/completions', 'grok-4.3',
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
