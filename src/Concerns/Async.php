<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;


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
     *
     * Rate limited status requests don't abort waiting for the running job, the next poll
     * waits as long as the provider asks for instead, unless that exceeds the polling deadline.
     *
     * @return void No return value; marks the response ready when polling completes
     */
    protected function wait() : void
    {
        $waited = 0;

        while( ( $retry = $this->pollAsync( $waited ) ) > 0 )
        {
            $this->ensureAsyncActive( $waited );
            $seconds = $this->sleepSeconds( $waited, $retry );
            $this->sleepAsync( $seconds );
            $waited += $seconds;
        }
    }


    /**
     * Pauses before the next polling attempt.
     *
     * @param int $seconds Number of seconds to wait
     * @return void No return value; resumes after the requested pause
     */
    protected function sleepAsync( int $seconds ) : void
    {
        sleep( $seconds );
    }


    /**
     * Returns the seconds elapsed since polling started.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @return float Elapsed seconds
     */
    private function asyncElapsed( int $waited ) : float
    {
        return max( $waited, microtime( true ) - $this->asyncStartedAt );
    }


    /**
     * Raises an exception when the asynchronous polling deadline has elapsed.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @return void No return value; throws when the polling deadline has elapsed
     */
    private function ensureAsyncActive( int $waited = 0 ) : void
    {
        if( $this->asyncTimeout === 0 ) {
            return;
        }

        if( $this->asyncElapsed( $waited ) >= $this->asyncTimeout ) {
            throw new PrismaException( sprintf( 'Asynchronous operation timed out after %d seconds', $this->asyncTimeout ) );
        }
    }


    /**
     * Polls once and returns the seconds to wait before polling again.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @return int Seconds until the next poll or zero if the response is ready
     * @throws RateLimitException If the provider asks to wait beyond the polling deadline
     */
    private function pollAsync( int $waited ) : int
    {
        try {
            return $this->ready() ? 0 : $this->asyncRetry;
        } catch( RateLimitException $e ) {
            if( $this->asyncTimeout > 0 && $this->asyncElapsed( $waited ) + (int) $e->retryAfter() > $this->asyncTimeout ) {
                throw $e;
            }

            return max( $this->asyncRetry, (int) $e->retryAfter() );
        }
    }


    /**
     * Returns the next sleep interval without exceeding the polling deadline.
     *
     * @param int $waited Seconds spent in polling sleeps
     * @param int $retry Seconds until the next poll
     * @return int Seconds to sleep
     */
    private function sleepSeconds( int $waited, int $retry ) : int
    {
        if( $this->asyncTimeout === 0 ) {
            return $retry;
        }

        return max( 1, min( $retry, (int) ceil( $this->asyncTimeout - $this->asyncElapsed( $waited ) ) ) );
    }
}
