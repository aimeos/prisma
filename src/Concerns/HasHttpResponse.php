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
     * @return self Same instance for fluent chaining
     */
    public function withMaxResponseSize( int $bytes ) : self
    {
        $this->maxResponseSize = max( 1, $bytes );
        return $this;
    }


    /**
     * Decodes the body of a failed response into an array.
     *
     * Error bodies are not guaranteed to be JSON: gateways and proxies in front of the provider
     * answer with HTML pages or plain text and don't know the format of the API they shield. What
     * the caller needs in that case is the status code, so decoding must not throw and hide it -
     * a rate limited or overloaded request has to raise its own exception and not a JSON error.
     * An undecodable, unreadable or oversized body is therefore reported as no error data at all.
     *
     * @param ResponseInterface $response HTTP response with a status code outside the 2xx range
     * @return array<string, mixed> Decoded response data or an empty array
     */
    protected function errorData( ResponseInterface $response ) : array
    {
        try {
            $data = json_decode( $this->readBody( $response ), true );
        } catch( \Aimeos\Prisma\Exceptions\PrismaException | \RuntimeException $e ) {
            // oversized bodies and read errors of the stream, e.g. if the connection was reset
            return [];
        }

        /** @var array<string, mixed> */
        return is_array( $data ) ? $data : [];
    }


    /**
     * Returns the error message of the decoded response data.
     *
     * Providers report the error either as string or as object with a message, some only
     * as message at the top level. FastAPI based APIs use "detail" instead, see errorDetail().
     * A string error next to a message is the status phrase only, e.g. "Bad Request", so the
     * message is preferred then.
     *
     * @param array<string, mixed> $data Decoded response data
     * @return string|null Error message or NULL if the data contains none
     */
    protected function errorMessage( array $data ) : ?string
    {
        $error = $data['error'] ?? null;

        $msg = is_array( $error ) ? ( $error['message'] ?? null ) : null;
        $msg = is_string( $msg ) && $msg !== '' ? $msg : ( $data['message'] ?? null );
        $msg = is_string( $msg ) && $msg !== '' ? $msg : $error;
        $msg = is_string( $msg ) && $msg !== '' ? $msg : $this->errorDetail( $data['detail'] ?? null );

        return is_string( $msg ) && $msg !== '' ? $msg : null;
    }


    /**
     * Decodes a JSON response body into an array.
     *
     * @param ResponseInterface $response HTTP response whose body will be decoded
     * @return array<string, mixed> Decoded response data
     */
    protected function fromJson( ResponseInterface $response ) : array
    {
        $body = $this->readBody( $response );
        $data = json_decode( $body, true );

        if( !is_array( $data ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( sprintf(
                'Invalid JSON response (HTTP %1$s): %2$s', $response->getStatusCode(), $body
            ) );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }


    /**
     * Returns the message of a FastAPI "detail" error.
     *
     * FastAPI reports validation errors as list of objects with a "msg" each, other errors
     * as string or as object with a message.
     *
     * @param mixed $detail Value of the "detail" key of the decoded response data
     * @return string|null Error message or NULL if the detail contains none
     */
    private function errorDetail( mixed $detail ) : ?string
    {
        if( is_array( $detail ) ) {
            $detail = array_is_list( $detail )
                ? join( ', ', array_filter( array_column( $detail, 'msg' ), 'is_string' ) )
                : $detail['message'] ?? null;
        }

        return is_string( $detail ) && $detail !== '' ? $detail : null;
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
     * @throws \RuntimeException When the body can't be read, e.g. if the connection was reset
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
        $retryAfter = $this->retryHeader( $response );

        if( $limit === '' && $remaining === '' && $reset === '' && $retryAfter === null ) {
            return null;
        }

        return new \Aimeos\Prisma\Values\RateLimit(
            $limit !== '' ? (int) $limit : null,
            $remaining !== '' ? (int) $remaining : null,
            $reset !== '' ? $reset : null,
            $retryAfter,
        );
    }


    /**
     * Returns the seconds to wait from the Retry-After header.
     *
     * @param ResponseInterface $response HTTP response
     * @return int|null Seconds to wait or NULL if the header is missing or invalid
     */
    protected function retryHeader( ResponseInterface $response ) : ?int
    {
        $value = trim( $response->getHeaderLine( 'retry-after' ) );

        if( ctype_digit( $value ) ) {
            return (int) $value;
        }

        // the header can also contain an HTTP date instead of seconds
        return $value !== '' && ( $time = strtotime( $value ) ) !== false ? max( 0, $time - time() ) : null;
    }


    /**
     * Throws a typed exception based on the HTTP status code.
     *
     * @param int $status HTTP status code
     * @param string $message Error message
     * @param ResponseInterface|null $response HTTP response for the Retry-After header of rate limit errors
     * @return void No return value; always throws a status-specific exception
     * @throws \Aimeos\Prisma\Exceptions\PrismaException
     */
    protected function throw( int $status, string $message, ?ResponseInterface $response = null ) : void
    {
        switch( $status )
        {
            case 409:
            case 422:
            case 400: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $message );
            case 401: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $message );
            case 402: throw new \Aimeos\Prisma\Exceptions\PaymentRequiredException( $message );
            case 403: throw new \Aimeos\Prisma\Exceptions\ForbiddenException( $message );
            case 410:
            case 404: throw new \Aimeos\Prisma\Exceptions\NotFoundException( $message );
            case 413: throw new \Aimeos\Prisma\Exceptions\SizeException( $message );
            case 429: throw ( new \Aimeos\Prisma\Exceptions\RateLimitException( $message ) )
                ->withRetryAfter( $response ? $this->retryHeader( $response ) : null );
            case 502:
            case 504:
            case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $message );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $message );
        }
    }


    /**
     * Validates the HTTP response and throws for status codes outside the 2xx range.
     *
     * The status code decides which exception is raised, so a body that isn't the JSON error of
     * the provider only costs the message, not the type of the exception.
     *
     * @param ResponseInterface $response HTTP response
     * @return void No return value; returns normally for successful responses
     * @throws \Aimeos\Prisma\Exceptions\PrismaException
     */
    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) >= 200 && $status < 300 ) {
            return;
        }

        $msg = $this->errorMessage( $this->errorData( $response ) );
        $this->throw( $status, $msg ?? $response->getReasonPhrase(), $response );
    }
}
