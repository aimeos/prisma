<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Azure as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Azure extends Base implements Stream, Structure, Vectorize, Write
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
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-4o' );

        return $this->structuredCompletions(
            $this->endpoint( $model, 'chat/completions' ), $model,
            $this->messages( $this->content( $prompt, $files ) ),
            $schema, $options
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'user'] );
        $model = (string) $this->modelName( 'text-embedding-3-small' );

        return $this->embeddings( $this->endpoint( $model, 'embeddings' ), $model, $texts, $size, $options );
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
