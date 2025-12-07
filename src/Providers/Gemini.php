<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-goog-api-key', (string) $config['api_key'] );
        $this->baseUrl( 'https://generativelanguage.googleapis.com' );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = json_decode( $response->getBody()->getContents() )?->error?->message ?: $response->getReasonPhrase();

        switch( $response->getStatusCode() )
        {
            case 400:
            case 404: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
            case 401:
            case 403: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
            case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
        }
    }
}
