<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Azure extends Base
{
    use CallsTools;
    use OpenaiApi;


    protected const URL = 'https://api.cognitive.microsofttranslator.com';


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( rtrim( $this->config( $config, 'url', static::URL ), '/' ) . '/openai/v1/' );
    }
}
