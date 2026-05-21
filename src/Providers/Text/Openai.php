<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Openai extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'store', 'reasoning'] );

        if( $budget = $this->thinkingBudget() ) {
            $options['reasoning'] = ['budget_tokens' => $budget];
        }

        return $this->responses(
            'v1/responses', 'gpt-5',
            [['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )]],
            $options
        );
    }
}
