<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Azure extends Base
{
    use CallsTools;
    use OpenaiApi;


    private string $apiVersion;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        if( !isset( $config['url'] ) && !isset( $config['resource'] ) ) {
            throw new PrismaException( 'No Azure resource name or URL' );
        }

        $this->header( 'api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url' ) ?: 'https://' . $this->config( $config, 'resource' ) . '.openai.azure.com' );
        $this->apiVersion = $this->config( $config, 'api_version', '2024-10-21' );
    }


    /**
     * Builds the deployment endpoint URL for an Azure OpenAI operation.
     *
     * Azure routes by deployment name (the model) in the path and requires an
     * explicit api-version query parameter.
     *
     * @param string $model Deployment/model name
     * @param string $path Operation path (e.g. "chat/completions")
     * @return string Endpoint path
     */
    protected function endpoint( string $model, string $path ) : string
    {
        return 'openai/deployments/' . $model . '/' . $path . '?api-version=' . $this->apiVersion;
    }
}
