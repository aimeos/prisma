<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Perplexity extends Base
{
    use CallsTools;
    use OpenaiApi;


    protected const PROVIDER_TOOL_MAP = [
        'web_search' => ['type' => 'web_search', 'options' => ['filters', 'max_results', 'search_type', 'user_location']],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.perplexity.ai' ) );
    }


    /**
     * Maps provider-agnostic reasoning control to the Agent API.
     *
     * @return array<string, mixed>
     */
    protected function reasoningParams() : array
    {
        return $this->reasoningEnabled() === false ? ['reasoning' => ['effort' => 'minimal']] : [];
    }


    /**
     * Drops output items like search results the Agent API doesn't accept as input.
     *
     * @param array<int, array<string, mixed>> $output Response output items
     * @return array<int, array<string, mixed>> Input items
     */
    protected function replay( array $output ) : array
    {
        $types = ['message', 'function_call', 'function_call_output', 'mcp_approval_request', 'mcp_approval_response', 'mcp_call', 'mcp_list_tools'];
        return array_values( array_filter( $output, fn( $item ) => in_array( $item['type'] ?? '', $types, true ) ) );
    }
}
