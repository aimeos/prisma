<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Openrouter extends Base
{
    use CallsTools;
    use OpenaiApi { toolsParam as openaiToolsParam; }


    /** @var array<string, array<string, mixed>> */
    private static array $providerToolMap = [
        'web_search' => ['type' => 'openrouter:web_search', 'options' => [
            'allowed_domains' => 'include_domains',
            'blocked_domains' => 'exclude_domains',
            'search_engine',
        ]],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://openrouter.ai' ) );
    }


    /**
     * Builds the tools parameter in OpenRouter format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        return array_merge( $this->openaiToolsParam(), $this->mapProviderTools( self::$providerToolMap ) );
    }

}
