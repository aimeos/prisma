<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Groq as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Groq extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->completions(
            'openai/v1/chat/completions', 'openai/gpt-oss-120b',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty']
        );
    }
}
