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
     * The producer receives the response instance and returns a generator that yields each
     * chunk (text delta or \Aimeos\Prisma\Tools\Step) as it arrives while populating the
     * response. Iterate stream() to consume the chunks live; resolve() (or any draining
     * accessor) blocks until the stream is fully drained.
     *
     * @param \Closure $producer Stream producer: fn(static $response): \Generator
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
     * Eager counterpart to consuming the stream live: runs the producer generator to the end so
     * the response is fully assembled, e.g. for the non-streaming write() path that shares the
     * same generator loop as stream(). A no-op for a response that is not stream-backed or whose
     * stream was already consumed.
     *
     * @return static Fully resolved response
     */
    public function resolve() : static
    {
        foreach( $this->stream() as $chunk ) {} // drain to populate the response

        return $this;
    }


    /**
     * Streams the response chunks live while populating the response.
     *
     * Yields each chunk (text delta as string or \Aimeos\Prisma\Tools\Step for tool calls) as it
     * arrives. The producer is consumed once: drain it fully - iterate to the end or let an
     * accessor call resolve() - to assemble the response. A consumer that stops early leaves the
     * response unassembled and the producer is not restarted, so a later stream() or accessor
     * yields nothing. Yields nothing for a response that is not stream-backed.
     *
     * @return \Generator<int, mixed> Streamed chunks
     */
    public function stream() : \Generator
    {
        if( $this->streamProducer === null ) {
            return;
        }

        $producer = $this->streamProducer;
        $this->streamProducer = null; // consume once: a later stream() or accessor yields nothing

        yield from $producer( $this );
    }
}
