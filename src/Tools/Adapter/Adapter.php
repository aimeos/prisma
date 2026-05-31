<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Interface for tool adapters that can be executed by the LLM.
 */
interface Adapter
{
    /**
     * Executes the tool with the given arguments.
     *
     * @param array<string, mixed> $arguments Tool call arguments
     * @return string Tool execution result
     */
    public function __invoke( array $arguments ) : string;

    /**
     * Sets whether this tool can run concurrently with other tools.
     *
     * @param bool $concurrent True to allow concurrent execution
     * @return static Self for chaining
     */
    public function concurrent( bool $concurrent = true ) : static;

    /**
     * Returns whether this tool can run concurrently.
     *
     * @return bool True if the tool can run concurrently
     */
    public function isConcurrent() : bool;

    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string;

    /**
     * Sets a custom error handler for tool execution failures.
     *
     * @param callable(\Throwable, array<string, mixed>): string $handler Error handler
     * @return static Self for chaining
     */
    public function failed( callable $handler ) : static;

    /**
     * Returns the configured maximum number of calls.
     *
     * @return int Maximum number of calls
     */
    public function limit() : int;

    /**
     * Sets the maximum number of times this tool can be called.
     *
     * @param int $calls Maximum number of calls
     * @return static Self for chaining
     */
    public function max( int $calls ) : static;

    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string;

    /**
     * Returns the provider-specific options.
     *
     * @return array<string, mixed> Options
     */
    public function options() : array;

    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema;

    /**
     * Sets provider-specific options.
     *
     * @param array<string, mixed> $options Options
     * @return static Self for chaining
     */
    public function with( array $options ) : static;
}
