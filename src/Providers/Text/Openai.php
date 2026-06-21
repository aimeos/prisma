<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Chat;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Openai extends Base implements Chat, Structure, Write
{
    public function chat( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->responses(
            'v1/responses', 'gpt-5.5',
            [['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )]],
            $options, $callback
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->structuredResponses(
            'v1/responses', 'gpt-5.5',
            [['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )]],
            $schema, $options
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->responses(
            'v1/responses', 'gpt-5.5',
            [['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )]],
            $options
        );
    }
}
