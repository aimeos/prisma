<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Xai extends Base
{
    use CallsTools;
    use OpenaiApi { toolsParam as openaiToolsParam; }


    /** @var array<string, array<string, mixed>> */
    private static array $providerToolMap = [
        'web_search' => ['type' => 'web_search', 'options' => ['blocked_domains' => 'excluded_domains', 'search_context_size']],
        'code_execution' => ['type' => 'code_interpreter', 'options' => []],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.x.ai' ) );
    }


    /**
     * Maps the tool choice to the values supported by xAI.
     *
     * xAI supports "auto" and "required" but not "none", which is omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return match( $this->toolChoice() ) {
            self::AUTO => 'auto',
            self::REQ => 'required',
            default => null,
        };
    }


    /**
     * Builds the tools parameter in xAI format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        return array_merge( $this->openaiToolsParam(), $this->mapProviderTools( self::$providerToolMap ) );
    }
}
