<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Live streaming response resolution backed by a generator.
 */
trait Stream
{
    private ?\Closure $streamProducer = null;
    private ?\Generator $streamGen = null;
    private bool $streamDone = true;


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
        $instance->streamDone = false;

        return $instance;
    }


    /**
     * Drains the stream to completion and returns the resolved response.
     *
     * Eager counterpart to consuming the stream live: runs the producer generator to the end so
     * the response is fully assembled, e.g. for the non-streaming write() path that shares the
     * same generator loop as stream(). A no-op for a response that is not stream-backed.
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
     * Yields each chunk (text delta as string or \Aimeos\Prisma\Tools\Step for tool calls) as
     * it arrives. The producer generator is created once and memoized, so a consumer that stops
     * early can be resumed later (by resolve() or a second stream() call) and pick up the unread
     * remainder; the response is only marked done once the generator completes. Yields nothing
     * for a response that is not stream-backed.
     *
     * @return \Generator<int, mixed> Streamed chunks
     */
    public function stream() : \Generator
    {
        if( $this->streamDone ) {
            return;
        }

        $gen = $this->streamGen;

        if( $gen === null )
        {
            if( $this->streamProducer === null ) {
                return;
            }

            $producer = $this->streamProducer;
            $this->streamProducer = null; // release the captured scope once the generator exists

            /** @var \Generator<int, mixed> $gen */
            $gen = $producer( $this );
            $this->streamGen = $gen;
        }

        try
        {
            yield from $gen;
        }
        catch( \Throwable $e )
        {
            // The producer failed mid-stream: mark done and drop the aborted generator so a later
            // accessor does not re-enter it (which would raise a confusing secondary error).
            $this->streamGen = null;
            $this->streamDone = true;

            throw $e;
        }

        // Reached only when the generator ran to completion. A consumer that stops early instead
        // suspends here without finishing, leaving the generator resumable by resolve()/stream().
        $this->streamGen = null;
        $this->streamDone = true;
    }
}
