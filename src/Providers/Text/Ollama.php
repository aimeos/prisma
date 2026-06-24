<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Ollama as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Ollama extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'v1/chat/completions', 'llama4',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, $callback
        );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->structuredCompletions( 'v1/chat/completions', 'llama4', $prompt, $files, $schema, $options, 'json' );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        return $this->embeddings( 'v1/embeddings', 'nomic-embed-text', $texts, $size, [] );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'v1/chat/completions', 'llama4',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
