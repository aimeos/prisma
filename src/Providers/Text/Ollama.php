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
        $options['response_format'] = ['type' => 'json_object'];

        $schemaPrompt = $prompt . "\n\nRespond with ONLY valid JSON matching this schema:\n" . $schema->toString();

        $response = $this->completions(
            'v1/chat/completions', 'llama4',
            $this->messages( $this->content( $schemaPrompt, $files ) ),
            $options
        );

        $text = trim( $response->text() ?? '' );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', $text ) ?? $text;
        $structured = json_decode( $text, true ) ?: [];

        return $response->withStructured( $structured );
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
