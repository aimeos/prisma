<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Azure as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Azure extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-4o' );

        return $this->completions(
            $this->endpoint( $model, 'chat/completions' ), $model,
            $this->messages( $this->content( $prompt, $files ) ),
            $options, $callback
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-4o' );

        return $this->structuredCompletions(
            $this->endpoint( $model, 'chat/completions' ), $model,
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-4o' );

        return $this->completions(
            $this->endpoint( $model, 'chat/completions' ), $model,
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
