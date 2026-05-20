<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Openrouter extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->completions(
            'api/v1/chat/completions', 'openrouter/free',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty']
        );
    }
}
