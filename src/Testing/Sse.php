<?php

namespace Aimeos\Prisma\Testing;


/**
 * Builds Server-Sent Events (SSE) stream bodies for testing.
 *
 * Turns a list of events into the raw SSE text a streaming provider parses, so streaming
 * tests describe events as native arrays instead of hand-concatenating "event:"/"data:"
 * lines with escaped JSON. Covers the three provider styles: an optional event name
 * (Anthropic), array data that is JSON-encoded, and verbatim string data (e.g. "[DONE]").
 */
class Sse
{
    /**
     * Formats a list of events as an SSE stream body.
     *
     * Each event is an array with a required "data" (array, JSON-encoded; or string, emitted
     * verbatim) and an optional "event" name. A bare data array without a "data" key is also
     * accepted and treated as the payload.
     *
     * @param array<int, array<string, mixed>|string> $events Stream events
     * @return string SSE-formatted body
     */
    public static function from( array $events ) : string
    {
        $sse = '';

        foreach( $events as $event )
        {
            if( is_array( $event ) && isset( $event['event'] ) && is_string( $event['event'] ) ) {
                $sse .= 'event: ' . $event['event'] . "\n";
            }

            $data = is_array( $event ) && array_key_exists( 'data', $event ) ? $event['data'] : $event;
            $sse .= 'data: ' . ( is_string( $data ) ? $data : (string) json_encode( $data ) ) . "\n\n";
        }

        return $sse;
    }
}
