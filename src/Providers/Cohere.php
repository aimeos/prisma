<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Cohere extends Base
{
    use CallsTools;
    use OpenaiApi;

    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.cohere.ai' ) );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $msg = @$this->fromJson( $response )['message'] ?: $response->getReasonPhrase();
            $this->throw( match( $status ) {
                413 => 400,
                498 => 403,
                default => $status,
            }, is_string( $msg ) ? $msg : '' );
        }
    }
}
