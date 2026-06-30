<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Alibaba as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Alibaba extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        if( $this->hasProviderTool( 'web_search' ) ) {
            $options['enable_search'] = true;
        }

        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'compatible-mode/v1/chat/completions', 'qwen-vl-plus', $messages, $options );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        return $this->structuredCompletions(
            'compatible-mode/v1/chat/completions', 'qwen-vl-plus',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'instruct'] );

        return $this->embeddings( 'compatible-mode/v1/embeddings', 'text-embedding-v4', $texts, $size, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        if( $this->hasProviderTool( 'web_search' ) ) {
            $options['enable_search'] = true;
        }

        return $this->completions(
            'compatible-mode/v1/chat/completions', 'qwen-vl-plus',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
