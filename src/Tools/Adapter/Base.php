<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Abstract base class for tool adapters providing shared functionality.
 */
abstract class Base implements Adapter
{
    private bool $concurrent = false;
    private int $max = PHP_INT_MAX;

    /** @var callable(\Throwable, array<string, mixed>): string|null */
    private $errorHandler = null;

    /** @var array<string, mixed> */
    private array $options = [];


    /**
     * Executes the tool with the given arguments.
     *
     * Catches any error so a failing tool returns a message the model can act
     * on instead of aborting the tool loop. Non-string results are JSON encoded.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return string Tool execution result
     */
    public function __invoke( array $arguments ) : string
    {
        try {
            $result = $this->execute( $arguments );
        } catch( \Throwable $e ) {
            return $this->errorHandler
                ? ( $this->errorHandler )( $e, $arguments )
                : sprintf( 'Error: %s', $e->getMessage() );
        }

        return is_string( $result ) ? $result : (string) json_encode( $result );
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
     * Returns the configured maximum number of calls.
     *
     * @return int Maximum number of calls
     */
    public function limit() : int
    {
        return $this->max;
    }


    /**
     * Sets the maximum number of times this tool can be called.
     *
     * @param int $calls Maximum number of calls
     * @return static Self for chaining
     */
    public function max( int $calls ) : static
    {
        $this->max = $calls;
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
     * Executes the tool logic and returns the raw result.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return mixed Tool execution result
     */
    abstract protected function execute( array $arguments ) : mixed;
}
