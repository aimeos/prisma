<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Server-Sent Events (SSE) streaming for providers.
 */
trait HasHttpStream
{
    /**
     * Opens a streaming POST request and returns its already-validated body.
     *
     * Validates the response and captures the rate limit eagerly, so HTTP, auth and rate-limit
     * errors surface at the call site instead of later when the unread body is consumed.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Updated with this response's rate limit, left unchanged when the response carries none
     * @return \Psr\Http\Message\StreamInterface Validated, unread response body
     */
    protected function openStream( string $endpoint, array $params, ?\Aimeos\Prisma\Values\RateLimit &$rateLimit = null ) : \Psr\Http\Message\StreamInterface
    {
        $response = $this->streamClient()->post( $endpoint, [
            'json' => $params,
            'stream' => true,
            'timeout' => 600,       // bound the total stream to 10 min so a slow-drip stream cannot pin the process indefinitely
            'read_timeout' => 120,  // and fail fast if no data arrives for 120s within that window
        ] );

        $this->validate( $response );

        // keep the previous turn's rate limit if this response omits the headers
        $rateLimit = $this->getRateLimit( $response ) ?? $rateLimit;

        return $response->getBody();
    }


    /**
     * Sends a non-streaming POST request and returns its decoded, validated body.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Updated with this response's rate limit, left unchanged when the response carries none
     * @return array<string, mixed> Decoded response body
     */
    protected function post( string $endpoint, array $params, ?\Aimeos\Prisma\Values\RateLimit &$rateLimit = null ) : array
    {
        $response = $this->client()->post( $endpoint, ['json' => $params] );

        $this->validate( $response );

        $rateLimit = $this->getRateLimit( $response ) ?? $rateLimit;

        return $this->fromJson( $response );
    }


    /**
     * Opens a streaming request eagerly and wraps the tool loop as a lazy TextResponse.
     *
     * Opens and validates the first request up front (so HTTP, auth and rate-limit errors
     * surface at the call site), then hands the open body to the provider's turn loop, which
     * runs lazily inside the returned response.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $firstParams Request payload for the eagerly opened first turn
     * @param \Closure(\Aimeos\Prisma\Responses\TextResponse, \Psr\Http\Message\StreamInterface): \Generator<int, mixed> $loop Turn loop receiving the response and the open first-turn body
     * @return \Aimeos\Prisma\Responses\TextResponse Lazy streaming text response
     */
    protected function streamResponse( string $endpoint, array $firstParams, \Closure $loop ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $body = $this->openStream( $endpoint, $firstParams, $rateLimit );

        return \Aimeos\Prisma\Responses\TextResponse::fromStream(
            fn( \Aimeos\Prisma\Responses\TextResponse $res ) => $loop( $res, $body )
        )->withRateLimit( $rateLimit );
    }


    /**
     * Decodes an open SSE body stream and yields each decoded event.
     *
     * Joins consecutive "data:" lines of one event and JSON decodes them on the blank-line
     * boundary. The body is always closed, even if the consumer aborts.
     *
     * @param \Psr\Http\Message\StreamInterface $body Open response body, e.g. from openStream()
     * @return \Generator<int, array<string, mixed>> Decoded SSE events
     */
    protected function streamData( \Psr\Http\Message\StreamInterface $body ) : \Generator
    {
        try
        {
            $lines = [];

            foreach( $this->readLines( $body ) as $line )
            {
                if( $line !== '' )
                {
                    if( str_starts_with( $line, 'data:' ) )
                    {
                        $value = substr( $line, 5 );
                        $lines[] = str_starts_with( $value, ' ' ) ? substr( $value, 1 ) : $value;
                    }

                    continue;
                }

                // a blank line terminates the event
                if( ( $event = $this->streamEvent( $lines ) ) !== null ) {
                    yield $event;
                }

                $lines = [];
            }

            if( ( $event = $this->streamEvent( $lines ) ) !== null ) {
                yield $event;
            }
        }
        finally
        {
            $body->close();
        }
    }


    /**
     * Reads the stream in chunks and yields each complete line without its newline.
     *
     * Buffers reads (a chunk can end mid-line), carries the trailing partial line into the
     * next read, drops the flushed prefix so a long line is scanned once overall, and strips
     * a trailing "\r" from CRLF endings.
     *
     * @param \Psr\Http\Message\StreamInterface $body Response body stream
     * @return \Generator<int, string> Complete lines
     * @throws \Aimeos\Prisma\Exceptions\PrismaException When the stream stalls before end of data
     */
    private function readLines( \Psr\Http\Message\StreamInterface $body ) : \Generator
    {
        $buffer = '';
        $start = 0; // first byte not yet flushed as a line
        $scan = 0;  // next byte to search for a newline
        $total = 0; // bytes read so far for this stream

        while( !$body->eof() )
        {
            $chunk = $body->read( 8192 );

            if( $chunk === '' ) {
                break; // end of stream or a stalled read - distinguished after the loop
            }

            // bound the whole stream so a runaway or hostile response cannot grow unboundedly
            if( ( $total += strlen( $chunk ) ) > $this->maxResponseSize ) {
                throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Stream exceeds the maximum allowed size of ' . $this->maxResponseSize . ' bytes' );
            }

            $buffer .= $chunk;

            while( ( $pos = strpos( $buffer, "\n", $scan ) ) !== false )
            {
                yield rtrim( substr( $buffer, $start, $pos - $start ), "\r" );
                $start = $scan = $pos + 1;
            }

            $scan = strlen( $buffer );

            if( $start > 0 ) // drop the flushed prefix so the buffer stays bounded to the current line
            {
                $buffer = substr( $buffer, $start );
                $scan -= $start;
                $start = 0;
            }
        }

        // An empty read before EOF is only a stall when the per-read inactivity timeout
        // actually elapsed; any other empty read is treated as a clean end.
        if( !$body->eof() && ( $body->getMetadata( 'timed_out' ) ?? false ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'Stream stalled: no data received within the read timeout' );
        }

        if( $buffer !== '' ) {
            yield rtrim( $buffer, "\r" );
        }
    }


    /**
     * Decodes the accumulated "data:" lines of one SSE event.
     *
     * @param array<int, string> $lines Raw data lines of a single event
     * @return array<string, mixed>|null Decoded event, or null when there is nothing to emit
     * @throws \Aimeos\Prisma\Exceptions\PrismaException On a streamed error event
     */
    private function streamEvent( array $lines ) : ?array
    {
        if( !$lines ) {
            return null;
        }

        $data = implode( "\n", $lines );

        if( $data === '' || $data === '[DONE]' ) {
            return null;
        }

        $decoded = json_decode( $data, true );

        if( !is_array( $decoded ) ) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        if( isset( $decoded['error'] ) && is_array( $decoded['error'] ) )
        {
            /** @var array{message?: mixed} $error */
            $error = $decoded['error'];
            $message = $error['message'] ?? null;

            throw new \Aimeos\Prisma\Exceptions\PrismaException( is_string( $message ) ? $message : 'Streaming error' );
        }

        return $decoded;
    }


    /**
     * Validates a server-supplied slot index used as a streaming accumulator key.
     *
     * Anything outside 0..$count (an existing slot or the next new one) falls back to $default,
     * so a malformed or hostile index cannot inflate the accumulator with a huge or sparse key.
     *
     * @param mixed $index Raw index from the event
     * @param int $count Number of slots allocated so far
     * @param int $default Slot to use when the index is invalid
     * @return int Validated slot index
     */
    protected function streamSlot( mixed $index, int $count, int $default ) : int
    {
        return ( is_int( $index ) && $index >= 0 && $index <= $count ) ? $index : $default;
    }
}
