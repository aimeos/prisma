<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Azure extends Base
{
    use CallsTools;
    use OpenaiApi;


    private string $url;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->url = rtrim( $this->config( $config, 'url' ), '/' );

        $this->header( 'api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->url ? $this->url . '/openai/v1/' : null );
    }


    /**
     * Returns the path of an Azure OpenAI v1 API operation.
     *
     * Azure OpenAI has no global endpoint, so the URL of the resource is required.
     *
     * @param string $path Operation path (e.g. "chat/completions")
     * @return string Endpoint path relative to the base URL
     * @throws PrismaException If no Azure URL is configured
     */
    protected function endpoint( string $path ) : string
    {
        if( !$this->url ) {
            throw new PrismaException( 'No Azure URL' );
        }

        return $path;
    }
}
