<?php

namespace Aimeos\Prisma\Concerns;

use Psr\Http\Message\ResponseInterface;


/**
 * HTTP response handling for providers.
 */
trait HasHttpResponse
{
    /**
     * Extracts rate limit information from HTTP response headers.
     *
     * @param ResponseInterface $response HTTP response
     * @return array<string, mixed> Rate limit info
     */
    protected function getRateLimit( ResponseInterface $response ) : array
    {
        $rateLimit = [];

        if( $value = $response->getHeaderLine( 'x-ratelimit-limit' ) ) {
            $rateLimit['limit'] = (int) $value;
        }

        if( $value = $response->getHeaderLine( 'x-ratelimit-remaining' ) ) {
            $rateLimit['remaining'] = (int) $value;
        }

        if( $value = $response->getHeaderLine( 'x-ratelimit-reset' ) ) {
            $rateLimit['reset'] = $value;
        }

        if( $value = $response->getHeaderLine( 'retry-after' ) ) {
            $rateLimit['retryAfter'] = (int) $value;
        }

        return $rateLimit;
    }


    /**
     * Decodes a JSON response body into an array.
     *
     * @return array<string, mixed> Decoded response data
     */
    protected function fromJson( ResponseInterface $response ) : array
    {
        $body = $response->getBody()->getContents();
        $data = json_decode( $body, true );

        if( !is_array( $data ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Invalid JSON response: ' . $body );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    /**
     * Throws a typed exception based on the HTTP status code.
     *
     * @param int $status HTTP status code
     * @param string $message Error message
     * @throws \Aimeos\Prisma\Exceptions\PrismaException
     */
    protected function throw( int $status, string $message ) : void
    {
        switch( $status )
        {
            case 409:
            case 422:
            case 400: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $message );
            case 401: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $message );
            case 402: throw new \Aimeos\Prisma\Exceptions\PaymentRequiredException( $message );
            case 403: throw new \Aimeos\Prisma\Exceptions\ForbiddenException( $message );
            case 404: throw new \Aimeos\Prisma\Exceptions\NotFoundException( $message );
            case 413: throw new \Aimeos\Prisma\Exceptions\SizeException( $message );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $message );
            case 502:
            case 504:
            case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $message );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $message );
        }
    }


    /**
     * Validates the HTTP response and throws on non-200 status.
     *
     * @param ResponseInterface $response HTTP response
     * @throws \Aimeos\Prisma\Exceptions\PrismaException
     */
    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $json = $this->fromJson( $response );

        /** @var array<string, mixed> $errorObj */
        $errorObj = $json['error'] ?? [];
        $errorMsg = $errorObj['message'] ?? $json['message'] ?? $response->getReasonPhrase();

        $this->throw( $response->getStatusCode(), is_string( $errorMsg ) ? $errorMsg : '' );
    }
}
