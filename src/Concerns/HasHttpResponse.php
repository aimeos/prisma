<?php

namespace Aimeos\Prisma\Concerns;

use Psr\Http\Message\ResponseInterface;


/**
 * HTTP response handling for providers.
 */
trait HasHttpResponse
{
    /** Maximum bytes read for a single provider response, streamed or not, before it is rejected (64 MiB). */
    private int $maxResponseSize = 67108864;


    /**
     * Sets the maximum number of bytes read for a single provider response.
     *
     * Bounds the bytes consumed from one response so a runaway or hostile endpoint cannot grow the
     * read buffer or the assembled result (text, reasoning, tool-call arguments) without limit. It
     * applies to both streamed responses (the SSE reader) and non-streamed ones (the JSON body
     * read), per request: each tool-loop turn is bounded independently rather than spanning a whole
     * multi-turn conversation.
     *
     * @param int $bytes Maximum bytes per response (minimum 1)
     * @return self
     */
    public function withMaxResponseSize( int $bytes ) : self
    {
        $this->maxResponseSize = max( 1, $bytes );
        return $this;
    }


    /**
     * Decodes a JSON response body into an array.
     *
     * @return array<string, mixed> Decoded response data
     */
    protected function fromJson( ResponseInterface $response ) : array
    {
        $body = $this->readBody( $response );
        $data = json_decode( $body, true );

        if( !is_array( $data ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Invalid JSON response: ' . $body );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    /**
     * Reads a response body into a string, bounded by the configured maximum size.
     *
     * A non-streamed response is read in full to be JSON decoded. When the body length is known up
     * front (a buffered response) it is read in a single call and an oversized body is rejected
     * before any of it is read; a length-unknown body (e.g. a streamed error response) is read in
     * bounded chunks and rejected once it passes the limit. Either way a runaway or hostile response
     * cannot be pulled into an unbounded string - the same ceiling the streaming reader enforces.
     *
     * @param ResponseInterface $response HTTP response
     * @return string Response body, at most maxResponseSize bytes
     * @throws \Aimeos\Prisma\Exceptions\PrismaException When the body exceeds the maximum size
     */
    private function readBody( ResponseInterface $response ) : string
    {
        $stream = $response->getBody();

        // Fast path: a known length lets us reject an oversized body without reading it and pull
        // the rest in one call rather than growing a string chunk by chunk.
        if( ( $size = $stream->getSize() ) !== null )
        {
            if( $size > $this->maxResponseSize ) {
                throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Response exceeds the maximum allowed size of ' . $this->maxResponseSize . ' bytes' );
            }

            return $stream->getContents();
        }

        // Unknown length: read bounded chunks until the limit, tracking the length as we go so the
        // growing body is never measured again (read() may return fewer bytes than requested).
        $body = '';
        $len = 0;

        while( !$stream->eof() && $len <= $this->maxResponseSize ) {
            $body .= $chunk = $stream->read( max( 1, min( 65536, $this->maxResponseSize + 1 - $len ) ) );
            $len += strlen( $chunk );
        }

        if( $len > $this->maxResponseSize ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Response exceeds the maximum allowed size of ' . $this->maxResponseSize . ' bytes' );
        }

        return $body;
    }


    /**
     * Extracts rate limit information from HTTP response headers.
     *
     * @param ResponseInterface $response HTTP response
     * @return \Aimeos\Prisma\Values\RateLimit|null Rate limit info
     */
    protected function getRateLimit( ResponseInterface $response ) : ?\Aimeos\Prisma\Values\RateLimit
    {
        $limit = $response->getHeaderLine( 'x-ratelimit-limit' );
        $remaining = $response->getHeaderLine( 'x-ratelimit-remaining' );
        $reset = $response->getHeaderLine( 'x-ratelimit-reset' );
        $retryAfter = $response->getHeaderLine( 'retry-after' );

        if( $limit === '' && $remaining === '' && $reset === '' && $retryAfter === '' ) {
            return null;
        }

        return new \Aimeos\Prisma\Values\RateLimit(
            $limit !== '' ? (int) $limit : null,
            $remaining !== '' ? (int) $remaining : null,
            $reset !== '' ? $reset : null,
            $retryAfter !== '' ? (int) $retryAfter : null,
        );
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
