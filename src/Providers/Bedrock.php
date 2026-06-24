<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends Base
{
    use CallsTools;


    protected string $baseUrl;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->baseUrl = $this->config( $config, 'url', 'https://bedrock-runtime.us-east-1.amazonaws.com' );

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->baseUrl );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $msg = @$this->fromJson( $response )['message'] ?: $response->getReasonPhrase();
            $this->throw( $status, is_string( $msg ) ? $msg : '' );
        }
    }
}
