<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Requesty as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Requesty extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, [
            'temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'user', 'reasoning'
        ] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'chat/completions', 'openai/gpt-4o-mini', $messages, $options );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, [
            'temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'user', 'reasoning'
        ] );

        return $this->structuredCompletions(
            'chat/completions', 'openai/gpt-4o-mini',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'user'] );

        return $this->embeddings( 'embeddings', 'openai/text-embedding-3-small', $texts, $size, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, [
            'temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'stop', 'user', 'reasoning'
        ] );

        return $this->completions(
            'chat/completions', 'openai/gpt-4o-mini',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
