<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Deepseek as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Deepseek extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->completions(
            'v1/chat/completions', 'deepseek-v4-pro',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty']
        );
    }
}
