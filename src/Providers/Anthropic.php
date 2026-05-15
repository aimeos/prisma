<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Anthropic extends Base
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', $config['api_key'] );
        $this->header( 'anthropic-version', '2023-06-01' );
        $this->baseUrl( $config['url'] ?? 'https://api.anthropic.com' );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = @$this->fromJson( $response )['error']['message'] ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
