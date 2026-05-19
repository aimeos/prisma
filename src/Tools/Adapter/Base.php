<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Abstract base class for tool adapters providing shared functionality.
 */
abstract class Base implements Adapter
{
    private bool $concurrent = false;
    private int $counter = PHP_INT_MAX;

    /** @var callable(\Throwable, array<string, mixed>): string|null */
    private $errorHandler = null;

    /** @var array<string, mixed> */
    private array $options = [];


    /**
     * Executes the tool logic and returns the raw result.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return mixed Tool execution result
     */
    abstract protected function execute( array $arguments ) : mixed;


    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return string Tool execution result
     */
    public function __invoke( array $arguments ) : string
    {
        $this->decrement();

        try {
            $result = $this->execute( $arguments );

            return is_string( $result ) ? $result : (string) json_encode( $result );
        } catch( \Throwable $e ) {
            if( $this->errorHandler ) {
                return ( $this->errorHandler )( $e, $arguments );
            }
            return sprintf( 'Error: %s', $e->getMessage() );
        }
    }


    /**
     * Returns whether the tool can still be called.
     *
     * @return bool True if the tool can be called, false if exhausted
     */
    public function can() : bool
    {
        return $this->counter > 0;
    }


    /**
     * Sets whether this tool can run concurrently with other tools.
     *
     * @param bool $concurrent True to allow concurrent execution
     * @return static Self for chaining
     */
    public function concurrent( bool $concurrent = true ) : static
    {
        $this->concurrent = $concurrent;
        return $this;
    }


    /**
     * Returns the counter of remaining calls.
     *
     * @return int Remaining calls
     */
    public function counter() : int
    {
        return $this->counter;
    }


    /**
     * Sets a custom error handler for tool execution failures.
     *
     * @param callable(\Throwable, array<string, mixed>): string $handler Error handler
     * @return static Self for chaining
     */
    public function failed( callable $handler ) : static
    {
        $this->errorHandler = $handler;
        return $this;
    }


    /**
     * Returns whether this tool can run concurrently.
     *
     * @return bool True if the tool can run concurrently
     */
    public function isConcurrent() : bool
    {
        return $this->concurrent;
    }


    /**
     * Sets the maximum number of times this tool can be called.
     *
     * @param int $calls Maximum number of calls
     * @return static Self for chaining
     */
    public function max( int $calls ) : static
    {
        $this->counter = $calls;
        return $this;
    }


    /**
     * Returns the provider-specific options.
     *
     * @return array<string, mixed> Options
     */
    public function options() : array
    {
        return $this->options;
    }


    /**
     * Sets provider-specific options.
     *
     * @param array<string, mixed> $options Options
     * @return static Self for chaining
     */
    public function with( array $options ) : static
    {
        $this->options = $options;
        return $this;
    }


    /**
     * Decrements the call counter.
     */
    protected function decrement() : void
    {
        $this->counter--;
    }
}
