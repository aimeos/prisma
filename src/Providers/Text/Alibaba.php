<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Alibaba extends Base implements Write
{
    use CallsTools;
    use OpenaiApi;
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://dashscope-intl.aliyuncs.com' ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType() ?? 'image/png', $file->base64() )
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $extra = $this->mapProviderTools( ['web_search' => ['options' => []]] ) ? ['enable_search' => true] : [];

        return $this->completions(
            'compatible-mode/v1/chat/completions', 'qwen-vl-plus',
            $this->messages( $content ), $options,
            ['temperature', 'max_tokens', 'top_p', 'top_k'], $extra
        );
    }

}
