<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Requesty extends Base
{
    use CallsTools;
    use OpenaiApi;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $site = is_array( $config['site'] ?? null ) ? $config['site'] : [];
        $url = $this->config( $config, 'url', 'https://router.requesty.ai/v1' );

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->header( 'HTTP-Referer', $site['http_referer'] ?? null );
        $this->header( 'X-Title', $site['x_title'] ?? null );
        $this->baseUrl( rtrim( $url, '/' ) . '/' );
    }


    /**
     * Requesty supports automatic tool selection; unsupported forced choices are omitted.
     */
    protected function toolChoiceParam() : ?string
    {
        return $this->toolChoice() === self::AUTO ? 'auto' : null;
    }
}
