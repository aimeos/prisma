<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Openrouter extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );

        return $this->completions(
            'api/v1/chat/completions', 'openai/gpt-4o',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
