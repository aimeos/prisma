<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Live streaming response resolution backed by a generator.
 */
trait Stream
{
    private ?\Closure $streamProducer = null;


    /**
     * Creates a new instance backed by a streaming producer.
     *
     * @param \Closure $producer Stream producer: fn(static $response): \Generator yielding chunks
     * @return static New instance
     */
    public static function fromStream( \Closure $producer ) : static
    {
        $instance = new static;
        $instance->streamProducer = $producer;

        return $instance;
    }


    /**
     * Drains the stream to completion and returns the resolved response.
     *
     * No-op for a response that is not stream-backed or whose stream was already consumed.
     *
     * @return static Fully resolved response
     */
    public function resolve() : static
    {
        foreach( $this->stream() as $chunk ) {}

        return $this;
    }


    /**
     * Streams the response chunks live while populating the response.
     *
     * Consumed once: a consumer that stops early leaves the response unassembled and a
     * later stream() or accessor yields nothing.
     *
     * @return \Generator<int, mixed> Streamed chunks (text deltas and tool steps)
     */
    public function stream() : \Generator
    {
        if( $this->streamProducer === null ) {
            return;
        }

        $producer = $this->streamProducer;
        $this->streamProducer = null; // consume once

        yield from $producer( $this );
    }
}
