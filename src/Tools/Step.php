<?php

namespace Aimeos\Prisma\Tools;

use Aimeos\Prisma\Tools\Adapter\Adapter;


/**
 * Represents a single tool call step with its result.
 */
class Step implements \JsonSerializable
{
    private ?string $id;
    private string $name;
    private ?Adapter $tool;
    private string $result = '';
    private bool $done = false;

    /** @var array<string, mixed> */
    private array $arguments;

    /**
     * Initializes the step with tool call data.
     *
     * @param string|null $id Tool call ID
     * @param string $name Tool name
     * @param array<string, mixed> $arguments Tool call arguments
     * @param Adapter|null $tool Tool adapter instance
     */
    public function __construct( ?string $id, string $name, array $arguments, ?Adapter $tool = null )
    {
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
        $this->tool = $tool;
    }


    /**
     * Returns the step as a JSON string (id, name, arguments, result, done).
     *
     * @return string JSON representation
     */
    public function __toString() : string
    {
        return (string) json_encode( $this );
    }


    /**
     * Returns the tool call arguments.
     *
     * @return array<string, mixed> Tool call arguments
     */
    public function arguments() : array
    {
        return $this->arguments;
    }


    /**
     * Stores the tool execution result.
     *
     * @param string $result Tool execution result
     */
    public function complete( string $result ) : void
    {
        $this->result = $result;
        $this->done = true;
    }


    /**
     * Returns whether the tool call has been executed.
     *
     * @return bool True after complete() was called, false while the call is still pending
     */
    public function done() : bool
    {
        return $this->done;
    }


    /**
     * Returns the tool call ID.
     *
     * @return string|null Tool call ID
     */
    public function id() : ?string
    {
        return $this->id;
    }


    /**
     * Returns the step data for JSON serialization (excludes the tool adapter).
     *
     * @return array{id: string|null, name: string, arguments: array<string, mixed>, result: string, done: bool}
     */
    public function jsonSerialize() : array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->result,
            'done' => $this->done,
        ];
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return $this->name;
    }


    /**
     * Returns the tool execution result.
     *
     * @return string Tool execution result
     */
    public function result() : string
    {
        return $this->result;
    }


    /**
     * Returns the tool adapter instance.
     *
     * @return Adapter|null Tool adapter
     */
    public function tool() : ?Adapter
    {
        return $this->tool;
    }
}
