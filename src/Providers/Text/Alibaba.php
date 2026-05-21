<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structured;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Alibaba as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Alibaba extends Base implements Structured, Write
{
    public function structured( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        return $this->structuredCompletions(
            'compatible-mode/v1/chat/completions', 'qwen-vl-plus',
            $this->messages( $this->content( $prompt, $files ) ),
            $schema, $options
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

        if( $this->mapProviderTools( ['web_search' => ['options' => []]] ) ) {
            $options['enable_search'] = true;
        }

        return $this->completions(
            'compatible-mode/v1/chat/completions', 'qwen-vl-plus',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
