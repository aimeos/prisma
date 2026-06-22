<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Openai extends Base
{
    use CallsTools;
    use OpenaiApi;


    /** @var array<string, array<string, mixed>> */
    private static array $providerToolMap = [
        'web_search' => ['type' => 'web_search', 'options' => ['allowed_domains', 'search_context_size', 'user_location']],
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
        $this->header( 'authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.openai.com' ) );
    }


    /**
     * Builds the tools parameter in OpenAI format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $tools = [];

        foreach( $this->tools() as $tool )
        {
            $tools[] = [
                'type' => 'function',
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $this->toolParameters( $tool->schema() ),
                'strict' => $tool->schema()->isStrict(),
            ];
        }

        return array_merge( $tools, $this->mapProviderTools( self::$providerToolMap ) );
    }
}
