<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Kimi as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Kimi extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, [
            'reasoning_effort', 'thinking', 'stop', 'logprobs', 'top_logprobs',
            'prediction', 'prompt_cache_key', 'safety_identifier',
        ] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'v1/chat/completions', 'kimi-k3', $messages, $options );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, [
            'reasoning_effort', 'thinking', 'stop', 'logprobs', 'top_logprobs',
            'prediction', 'prompt_cache_key', 'safety_identifier',
        ] );

        return $this->structuredCompletions(
            'v1/chat/completions', 'kimi-k3',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, [
            'reasoning_effort', 'thinking', 'stop', 'logprobs', 'top_logprobs',
            'prediction', 'prompt_cache_key', 'safety_identifier',
        ] );

        return $this->completions(
            'v1/chat/completions', 'kimi-k3',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
