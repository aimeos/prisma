<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;


/**
 * Deferred response resolution by polling an async job.
 */
trait Async
{
    private ?\Closure $asyncPoll = null;
    private ?string $asyncJobId = null;
    private ?string $asyncFailed = null;

    private int $asyncRetry = 5;
    private int $asyncWait = 0;
    private int $asyncTimeout = 0;
    private float $asyncStartedAt = 0;


    /**
     * Creates a new instance with an async polling closure.
     *
     * @param \Closure $closure Polling closure that populates the response and returns true when done
     * @param int $retry Seconds between polling attempts
     * @param int $timeout Maximum polling time in seconds, zero for no limit
     * @param string|null $jobId Provider job ID for resuming the polling in another process
     * @return static New instance
     */
    public static function fromAsync( \Closure $closure, int $retry = 5, int $timeout = 0, ?string $jobId = null ) : static
    {
        $instance = new static;
        $instance->asyncPoll = $closure;
        $instance->asyncJobId = $jobId;
        $instance->asyncRetry = max( 1, $retry );
        $instance->asyncTimeout = max( 0, $timeout );
        $instance->asyncStartedAt = microtime( true );

        return $instance;
    }


    /**
     * Returns the provider job ID of an asynchronous response.
     *
     * Pass it unchanged to the provider's resume() method to continue polling in another
     * process, e.g. a later queue job, because pending responses can't be serialized. It's
     * returned for completed jobs too, so use ready() to test if the job is still running.
     *
     * @return string|null Provider job ID or NULL if the response can't be resumed
     */
    public function jobId() : ?string
    {
        return $this->asyncJobId;
    }


    /**
     * Returns whether the polled job has completed.
     *
     * Performs a single non-blocking poll; an eagerly built response is ready immediately.
     * A job the provider reported as failed doesn't change any more, so it isn't polled again
     * and ready() throws a failure with the same message for every later call instead. Only the
     * message is kept, not the exception, so the response stays serializable and each trace
     * points at the call that observed the failure.
     *
     * This reflects the async/poll lifecycle only. A response backed by a live stream (see
     * the Stream trait) leaves the poll flag untouched, so ready() returns true for it
     * regardless of how much of the stream has been consumed - drain the stream (iterate
     * stream() or read a text accessor) to assemble a streamed response, do not gate on
     * ready().
     *
     * @return bool True if the async job has completed
     * @throws FailedException If the job failed, again for every later call
     * @throws RateLimitException If the provider rate limits polling, retryAfter() returns the wait time then
     * @throws PrismaException If polling the provider failed, e.g. temporarily by a network error
     */
    public function ready() : bool
    {
        if( $this->asyncFailed !== null ) {
            throw new FailedException( $this->asyncFailed );
        }

        if( !( $closure = $this->asyncPoll ) ) {
            return true;
        }

        try {
            // every poll starts without an extra wait, only a rate limited one adds it again
            $this->asyncWait = 0;
            $done = $closure( $this );
        } catch( FailedException $e ) {
            // Failed jobs don't change any more, so don't request the provider again
            $this->asyncPoll = null;
            $this->asyncFailed = $e->getMessage();
            throw $e;
        } catch( RateLimitException $e ) {
            // Rate limits are temporary but the provider asks to wait before polling again
            $this->asyncWait = max( $this->asyncRetry, (int) $e->retryAfter() );
            throw $e;
        }

        if( $done ) {
            $this->asyncPoll = null;
            return true;
        }

        $this->ensureAsyncActive();
        return false;
    }


    /**
     * Returns the seconds to wait before polling a pending job again.
     *
     * Use it to delay a queue job that resumes polling, e.g. by releasing it back to the queue.
     * After a rate limited poll, it returns the seconds the provider asks to wait instead.
     *
     * @return int Polling interval of the provider in seconds or zero if the job completed or failed
     */
    public function retryAfter() : int
    {
        return $this->asyncPoll ? ( $this->asyncWait ?: $this->asyncRetry ) : 0;
    }


    /**
     * Sets the seconds to wait before polling the job again.
     *
     * Use it in polling closures of providers that estimate how long the job takes.
     *
     * @param int $seconds Polling interval in seconds, at least one second
     * @return static Same object for fluid method calls
     */
    public function withRetry( int $seconds ) : static
    {
        $this->asyncRetry = max( 1, $seconds );
        return $this;
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
            // only a wait the provider asks for can exceed the deadline, the polling interval is shortened like for other polls
            if( $this->asyncTimeout > 0 && $this->asyncElapsed( $waited ) + (int) $e->retryAfter() > $this->asyncTimeout ) {
                throw $e;
            }

            return $this->asyncWait;
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
