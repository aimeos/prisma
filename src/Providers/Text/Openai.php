<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Openai extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->responses(
            'v1/responses', 'gpt-5.5',
            $this->responsesInput( $prompt, $files ),
            $options, $callback
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->structuredResponses(
            'v1/responses', 'gpt-5.5',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'user'] );

        return $this->embeddings( 'v1/embeddings', 'text-embedding-3-small', $texts, $size, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->responses(
            'v1/responses', 'gpt-5.5',
            $this->responsesInput( $prompt, $files ),
            $options
        );
    }
}
