<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Meta as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Meta extends Base implements Stream, Structure, Write
{
    private const COMPLETION_OPTIONS = [
        'temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'seed',
        'parallel_tool_calls', 'prompt_cache_key', 'prompt_cache_retention', 'safety_identifier', 'service_tier',
    ];

    private const RESPONSE_OPTIONS = [
        'temperature', 'top_p', 'reasoning', 'include',
        'parallel_tool_calls', 'prompt_cache_key', 'prompt_cache_retention', 'safety_identifier', 'service_tier',
    ];


    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        if( $this->providerTools() )
        {
            $options = $this->allowed( $options, self::RESPONSE_OPTIONS );
            $input = $this->responsesInput( $prompt, $files );

            return $this->streamResponses( 'v1/responses', 'muse-spark-1.3', $input, $options );
        }

        $options = $this->allowed( $options, self::COMPLETION_OPTIONS );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'v1/chat/completions', 'muse-spark-1.3', $messages, $options );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;

        if( $this->providerTools() )
        {
            $options = $this->allowed( $options, self::RESPONSE_OPTIONS );

            return $this->structuredResponses(
                'v1/responses', 'muse-spark-1.3',
                $prompt, $files, $schema, $options, $mode
            );
        }

        $options = $this->allowed( $options, self::COMPLETION_OPTIONS );

        return $this->structuredCompletions(
            'v1/chat/completions', 'muse-spark-1.3',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        if( $this->providerTools() )
        {
            $options = $this->allowed( $options, self::RESPONSE_OPTIONS );

            return $this->responses(
                'v1/responses', 'muse-spark-1.3',
                $this->responsesInput( $prompt, $files ),
                $options
            );
        }

        $options = $this->allowed( $options, self::COMPLETION_OPTIONS );

        return $this->completions(
            'v1/chat/completions', 'muse-spark-1.3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
