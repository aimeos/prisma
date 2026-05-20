<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Alibaba as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Alibaba extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $extra = $this->mapProviderTools( ['web_search' => ['options' => []]] ) ? ['enable_search' => true] : [];

        return $this->completions(
            'compatible-mode/v1/chat/completions', 'qwen3-vl-plus',
            $this->messages( $this->content( $prompt, $files ) ),
            $options, ['temperature', 'max_tokens', 'top_p', 'top_k'], $extra
        );
    }
}
