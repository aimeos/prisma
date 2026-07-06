<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Z as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Z extends Base implements Stream, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'do_sample', 'tool_stream', 'reasoning_effort'] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'api/paas/v4/chat/completions', 'glm-5.2', $messages, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'do_sample', 'tool_stream', 'reasoning_effort'] );

        return $this->completions(
            'api/paas/v4/chat/completions', 'glm-5.2',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}

