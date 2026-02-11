<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Async trait for handling asynchronous responses.
 */
trait Async
{
    private ?\Closure $async = null;
    private int $retry = 5;


    abstract protected function content() : ?string;

    abstract protected function setContent( ?string $content ) : ?string;


    /**
     * Create a file instance from an asynchronous closure.
     *
     * @param \Closure $closure Closure which returns the file content when invoked or NULL when not yet ready
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

        return (bool) $this->setContent( $closure() );
    }


    /**
     * Waits until the asynchronous file content is ready and returns it.
     *
     * @return string|null Binary content
     */
    protected function wait() : ?string
    {
        $content = $this->content();

        if( $content || !( $closure = $this->async ) ) {
            return $content;
        }

        while( ( $content = $this->setContent( $closure() ) ) === null ) {
            sleep( $this->retry );
        }

        return $content;
    }
}
