<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Asynchronous response polling.
 */
trait Async
{
    private ?\Closure $async = null;
    private int $retry = 5;


    /**
     * Returns whether the response is empty.
     *
     * @return bool True if empty
     */
    abstract public function empty() : bool;


    /**
     * Creates a new instance with an async polling closure.
     *
     * @param \Closure $closure Polling closure that populates the response
     * @param int $retry Seconds between polling attempts
     * @return static New instance
     */
    public static function fromAsync( \Closure $closure, int $retry = 5 ) : static
    {
        $instance = new static;
        $instance->async = $closure;
        $instance->retry = $retry;

        return $instance;
    }


    /**
     * Returns whether the async response is ready.
     *
     * @return bool True if ready or not async
     */
    public function ready() : bool
    {
        if( !$this->async ) {
            return true;
        }

        $closure = $this->async;

        return (bool) $closure( $this );
    }


    /**
     * Blocks until the async response is ready.
     */
    protected function wait() : void
    {
        if( !$this->empty() || !( $closure = $this->async ) ) {
            return;
        }

        while( !$closure( $this ) ) {
            sleep( $this->retry );
        }
    }
}
