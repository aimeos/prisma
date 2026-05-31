<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Abstract decorator for tool adapters.
 */
abstract class Decorator implements Adapter
{
    private Adapter $adapter;


    /**
     * Initializes the decorator with the wrapped adapter.
     *
     * @param Adapter $adapter Wrapped adapter instance
     */
    public function __construct( Adapter $adapter )
    {
        $this->adapter = $adapter;
    }


    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return string Tool execution result
     */
    public function __invoke( array $arguments ) : string
    {
        return ( $this->adapter )( $arguments );
    }


    /**
     * Returns the configured maximum number of calls.
     *
     * @return int Maximum number of calls
     */
    public function limit() : int
    {
        return $this->adapter->limit();
    }


    /**
     * Sets whether this tool can run concurrently with other tools.
     *
     * @param bool $concurrent True to allow concurrent execution
     * @return static Self for chaining
     */
    public function concurrent( bool $concurrent = true ) : static
    {
        $this->adapter->concurrent( $concurrent );
        return $this;
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return $this->adapter->description();
    }


    /**
     * Returns whether this tool can run concurrently.
     *
     * @return bool True if the tool can run concurrently
     */
    public function isConcurrent() : bool
    {
        return $this->adapter->isConcurrent();
    }


    /**
     * Sets a custom error handler for tool execution failures.
     *
     * @param callable(\Throwable, array<string, mixed>): string $handler Error handler
     * @return static Self for chaining
     */
    public function failed( callable $handler ) : static
    {
        $this->adapter->failed( $handler );
        return $this;
    }


    /**
     * Sets the maximum number of times this tool can be called.
     *
     * @param int $calls Maximum number of calls
     * @return static Self for chaining
     */
    public function max( int $calls ) : static
    {
        $this->adapter->max( $calls );
        return $this;
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return $this->adapter->name();
    }


    /**
     * Returns the provider-specific options.
     *
     * @return array<string, mixed> Options
     */
    public function options() : array
    {
        return $this->adapter->options();
    }


    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema
    {
        return $this->adapter->schema();
    }


    /**
     * Sets provider-specific options.
     *
     * @param array<string, mixed> $options Options
     * @return static Self for chaining
     */
    public function with( array $options ) : static
    {
        $this->adapter->with( $options );
        return $this;
    }
}
