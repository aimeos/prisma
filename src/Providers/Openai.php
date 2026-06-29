<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Openai extends Base
{
    use CallsTools;
    use OpenaiApi;


    protected const PROVIDER_TOOL_MAP = [
        'web_search' => ['type' => 'web_search', 'options' => ['allowed_domains', 'search_context_size', 'user_location'], 'except' => [self::STRUCT]],
        'code_execution' => ['type' => 'code_interpreter', 'container' => ['type' => 'auto'], 'options' => ['container']],
        'file_search' => ['type' => 'file_search', 'options' => ['vector_store_ids', 'max_num_results']],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'OpenAI-Organization', $config['organization'] ?? null );
        $this->header( 'OpenAI-Project', $config['project'] ?? null );
        $this->header( 'authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.openai.com' ) );
    }
}
