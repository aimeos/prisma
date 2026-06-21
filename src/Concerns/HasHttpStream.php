<?php

namespace Aimeos\Prisma\Concerns;

use GuzzleHttp\Psr7\Utils;


/**
 * Server-Sent Events (SSE) streaming for providers.
 */
trait HasHttpStream
{
    protected ?\Aimeos\Prisma\Values\RateLimit $streamRateLimit = null;


    /**
     * Streams a POST request and yields each decoded SSE event.
     *
     * Opens the request with Guzzle's streaming body, no total timeout but a per-read
     * inactivity timeout, then reads the response line by line. Consecutive "data:"
     * lines of one event are joined per the SSE spec and JSON decoded on the blank-line
     * boundary; comment, "event:" and "[DONE]" lines are skipped. A streamed error event
     * (top-level "error" object) is raised as an exception so it surfaces like a non-200
     * response does. The response body is always closed, even if the consumer aborts.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload
     * @return \Generator<int, array<string, mixed>> Decoded SSE events
     */
    protected function streamData( string $endpoint, array $params ) : \Generator
    {
        $response = $this->client()->post( $endpoint, [
            'json' => $params,
            'stream' => true,
            'timeout' => 0,         // no total cap: streamed responses are long-lived
            'read_timeout' => 120,  // but fail fast if no data arrives for 120s so a stalled stream cannot pin the process
        ] );

        $this->validate( $response );

        $this->streamRateLimit = $this->getRateLimit( $response );

        $body = $response->getBody();

        try
        {
            $lines = [];

            while( !$body->eof() )
            {
                $line = rtrim( Utils::readLine( $body ), "\r\n" );

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
}
