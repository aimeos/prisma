<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Live streaming response resolution backed by a generator.
 */
trait Stream
{
    /** @var array<int, \Closure(static, ?\Throwable): void> Completion callbacks run after full stream consumption */
    private array $streamComplete = [];

    /** @var \Closure(static): \Generator<int, mixed>|null Stream producer consumed by stream() */
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
     * Runs the callback after the stream is fully consumed or fails.
     *
     * @param \Closure $callback Completion callback: fn(static $response, ?\Throwable $error): void
     * @return static Same response instance
     */
    public function onComplete( \Closure $callback ) : static
    {
        $this->streamComplete[] = $callback;
        return $this;
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

        try
        {
            yield from $producer( $this );
            $this->complete();
        }
        catch( \Throwable $e )
        {
            $this->complete( $e );
            throw $e;
        }
    }


    /**
     * Notifies registered stream completion callbacks once.
     *
     * @param \Throwable|null $error Stream failure, or null when the stream completed successfully
     */
    private function complete( ?\Throwable $error = null ) : void
    {
        $callbacks = $this->streamComplete;
        $this->streamComplete = [];

        foreach( $callbacks as $callback ) {
            $callback( $this, $error );
        }
    }
}
