<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Async trait for handling asynchronous responses.
 */
trait Async
{
    private ?\Closure $async = null;
    private int $retry = 5;


    abstract public function empty() : bool;


    /**
     * Create a file instance from an asynchronous closure.
     *
     * @param \Closure $closure Closure which returns the file content when invoked or NULL when not yet ready
     * @param int $retry Number of seconds to wait between retries when checking if the content is ready
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
     * Checks whether the file content is ready to be retrieved.
     *
     * @return bool True if the file content is ready, false otherwise
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
     * Waits until the asynchronous file content is ready and returns it.
     *
     * @return void
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
