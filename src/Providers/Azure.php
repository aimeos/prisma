<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Azure extends Base
{
    use CallsTools;
    use OpenaiApi;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        if( !isset( $config['url'] ) && !isset( $config['resource'] ) && !isset( $config['translator_url'] ) ) {
            throw new PrismaException( 'No Azure resource name or URL' );
        }

        $this->header( 'api-key', $this->config( $config, 'api_key' ) );
        $url = $this->config( $config, 'url' ) ?: 'https://' . $this->config( $config, 'resource' ) . '.openai.azure.com';
        $this->baseUrl( rtrim( $url, '/' ) . '/openai/v1/' );
    }
}
