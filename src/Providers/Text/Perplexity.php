<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Perplexity as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Perplexity extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->streamResponses(
            'v1/agent', 'perplexity/sonar',
            $this->responsesInput( $prompt, $files ),
            $this->options( $options )
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        // The Agent API keeps the chat completions response_format shape for structured output
        [$prompt, $options] = $this->structuredRequest( $prompt, $schema, $this->options( $options ), $mode );

        $response = $this->responses(
            'v1/agent', 'perplexity/sonar',
            $this->responsesInput( $prompt, $files ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->responses(
            'v1/agent', 'perplexity/sonar',
            $this->responsesInput( $prompt, $files ),
            $this->options( $options )
        );
    }


    /**
     * Filters the request options and applies the default effort level.
     *
     * The "fast" effort level replaces Sonar and enables web search by default.
     *
     * @param array<string, mixed> $options Request options
     * @return array<string, mixed> Allowed request options
     */
    private function options( array $options ) : array
    {
        return $this->allowed( $options, ['effort', 'reasoning', 'temperature', 'top_p'] ) + ['effort' => 'fast'];
    }
}
