<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Deferred response resolution by polling an async job.
 */
trait Async
{
    private ?\Closure $asyncPoll = null;

    /** @var \Closure|null fn(int $seconds): void */
    private ?\Closure $asyncSleep = null;

    private bool $asyncDone = true;
    private int $asyncRetry = 5;


    /**
     * Creates a new instance with an async polling closure.
     *
     * @param \Closure $closure Polling closure that populates the response and returns true when done
     * @param int $retry Seconds between polling attempts
     * @param \Closure|null $sleep Sleep override fn(int $seconds): void; lets tests poll without real delays
     * @return static New instance
     */
    public static function fromAsync( \Closure $closure, int $retry = 5, ?\Closure $sleep = null ) : static
    {
        $instance = new static;
        $instance->asyncPoll = $closure;
        $instance->asyncRetry = $retry;
        $instance->asyncSleep = $sleep;
        $instance->asyncDone = false;

        return $instance;
    }


    /**
     * Returns whether the polled job has completed.
     *
     * Performs a single non-blocking poll; an eagerly built response is ready immediately.
     *
     * This reflects the async/poll lifecycle only. A response backed by a live stream (see
     * the Stream trait) leaves the poll flag untouched, so ready() returns true for it
     * regardless of how much of the stream has been consumed - drain the stream (iterate
     * stream() or read a text accessor) to assemble a streamed response, do not gate on
     * ready().
     *
     * @return bool True if the async job has completed
     */
    public function ready() : bool
    {
        if( $this->asyncDone ) {
            return true;
        }

        $closure = $this->asyncPoll;

        if( $closure && $closure( $this ) ) {
            $this->asyncDone = true;
        }

        return $this->asyncDone;
    }


    /**
     * Blocks by polling until the response is populated.
     */
    protected function wait() : void
    {
        if( $this->asyncDone ) {
            return;
        }

        if( $closure = $this->asyncPoll )
        {
            // Pause via the injected sleep when set (so tests poll without real delays) and
            // fall back to a real sleep() otherwise.
            $sleep = $this->asyncSleep ?? fn( int $seconds ) => sleep( $seconds );

            while( !$closure( $this ) ) {
                $sleep( $this->asyncRetry );
            }

            $this->asyncDone = true;
        }
    }
}
