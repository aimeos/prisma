<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;


/**
 * Deferred response resolution by polling an async job.
 */
trait Async
{
    private ?\Closure $asyncPoll = null;

    private bool $asyncDone = true;
    private int $asyncRetry = 5;
    private int $asyncTimeout = 0;
    private float $asyncStartedAt = 0;


    /**
     * Creates a new instance with an async polling closure.
     *
     * @param \Closure $closure Polling closure that populates the response and returns true when done
     * @param int $retry Seconds between polling attempts
     * @param int $timeout Maximum polling time in seconds, zero for no limit
     * @return static New instance
     */
    public static function fromAsync( \Closure $closure, int $retry = 5, int $timeout = 0 ) : static
    {
        $instance = new static;
        $instance->asyncPoll = $closure;
        $instance->asyncRetry = max( 1, $retry );
        $instance->asyncTimeout = max( 0, $timeout );
        $instance->asyncStartedAt = microtime( true );
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

        if( $closure ) {
            if( $closure( $this ) ) {
                return $this->asyncDone = true;
            }

            $this->ensureAsyncActive();
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
            $waited = 0;

            while( !$closure( $this ) )
            {
                $this->ensureAsyncActive( $waited );
                $seconds = $this->sleepSeconds( $waited );
                $this->sleepAsync( $seconds );
                $waited += $seconds;
            }

            $this->asyncDone = true;
        }
    }


    /**
     * Pauses before the next polling attempt.
     *
     * @param int $seconds Number of seconds to wait
     * @return void
     */
    protected function sleepAsync( int $seconds ) : void
    {
        sleep( $seconds );
    }


    /**
     * Raises an exception when the asynchronous polling deadline has elapsed.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @return void
     */
    private function ensureAsyncActive( int $waited = 0 ) : void
    {
        if( $this->asyncTimeout === 0 ) {
            return;
        }

        $elapsed = max( $waited, microtime( true ) - $this->asyncStartedAt );

        if( $elapsed >= $this->asyncTimeout ) {
            throw new PrismaException( sprintf( 'Asynchronous operation timed out after %d seconds', $this->asyncTimeout ) );
        }
    }


    /**
     * Returns the next sleep interval without exceeding the polling deadline.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @return int Seconds to sleep
     */
    private function sleepSeconds( int $waited ) : int
    {
        if( $this->asyncTimeout === 0 ) {
            return $this->asyncRetry;
        }

        $elapsed = max( $waited, microtime( true ) - $this->asyncStartedAt );

        return max( 1, min( $this->asyncRetry, (int) ceil( $this->asyncTimeout - $elapsed ) ) );
    }
}
