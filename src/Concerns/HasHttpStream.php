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
     * Sends the request with Guzzle's streaming body (no total timeout but a per-read
     * inactivity timeout), validates the response status and captures the rate limit from
     * the headers - all eagerly, so HTTP, auth and rate-limit errors surface at the call
     * site instead of later when the body is consumed. The rate limit is returned through
     * the by-reference argument (per call, not shared instance state, so interleaved streams
     * cannot overwrite each other), while the body itself is returned unread for streamData()
     * to decode lazily.
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

        // Keep the previous turn's rate limit if this response omits the headers, so a later
        // turn without them does not wipe the value an earlier turn reported.
        $rateLimit = $this->getRateLimit( $response ) ?? $rateLimit;

        return $response->getBody();
    }


    /**
     * Opens a streaming request eagerly and wraps the tool loop as a lazy TextResponse.
     *
     * Shared backbone for every provider's stream*() method: it opens and validates the first
     * request up front (so HTTP, auth and rate-limit errors surface at the call site), captures
     * this request's rate limit into a local that an interleaved stream cannot overwrite, and
     * hands the open body plus that rate limit to the provider's turn loop. The loop runs lazily
     * inside the returned response, so iterating it consumes the stream live.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $firstParams Request payload for the eagerly opened first turn
     * @param \Closure(\Aimeos\Prisma\Responses\TextResponse, \Psr\Http\Message\StreamInterface, ?\Aimeos\Prisma\Values\RateLimit): \Generator<int, mixed> $loop Turn loop receiving the response, open first-turn body and its rate limit
     * @return \Aimeos\Prisma\Responses\TextResponse Lazy streaming text response
     */
    protected function streamResponse( string $endpoint, array $firstParams, \Closure $loop ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $body = $this->openStream( $endpoint, $firstParams, $rateLimit );

        return \Aimeos\Prisma\Responses\TextResponse::fromStream(
            fn( \Aimeos\Prisma\Responses\TextResponse $res ) => $loop( $res, $body, $rateLimit )
        )->withRateLimit( $rateLimit );
    }


    /**
     * Decodes an open SSE body stream and yields each decoded event.
     *
     * Reads the body line by line. Consecutive "data:" lines of one event are joined per
     * the SSE spec and JSON decoded on the blank-line boundary; comment, "event:" and
     * "[DONE]" lines are skipped. A streamed error event (top-level "error" object) is
     * raised as an exception so it surfaces like a non-200 response does. The body is
     * always closed, even if the consumer aborts.
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
     * next read and flushes the final line at end of stream. Newlines are located from a
     * moving scan offset and the flushed prefix is dropped after each read, so a single line
     * spanning many reads is scanned once overall rather than re-scanned per read. A trailing
     * "\r" from CRLF endings is stripped. An empty read whose inactivity timeout actually
     * elapsed (per the stream's "timed_out" metadata) is raised as a stall rather than
     * silently truncated; an empty read for any other reason is treated as a clean end.
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

            // bound the whole stream so a runaway or hostile response cannot grow the read buffer
            // or the assembled result (content, tool-call arguments, ...) without limit
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

        // A clean end leaves the stream at EOF. An empty read before EOF is only a stall when
        // the per-read inactivity timeout actually elapsed; a stream that returned empty for
        // another reason (e.g. the server kept the connection open after the final event, or
        // closed it without flipping the EOF flag) is treated as a clean end so a fully
        // received response is not turned into an error.
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
     * Streamed deltas reference their slot by a 0-based index; a malformed or hostile stream could
     * send a non-integer, negative or far-out-of-range value that would inflate the accumulator
     * with a huge or sparse key. Anything outside 0..$count (an existing slot or the next new one)
     * falls back to $default.
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
