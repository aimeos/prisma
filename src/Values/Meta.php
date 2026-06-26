<?php

namespace Aimeos\Prisma\Values;


/**
 * Meta information from a provider response.
 *
 * Behaves like the meta array it replaced (array access, iteration, count and JSON) while
 * adding typed accessors for the fields shared across providers.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class Meta implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    use \Aimeos\Prisma\Concerns\AsArray;


    /**
     * Initializes the meta information.
     *
     * @param array<string, mixed> $data Raw meta map
     */
    public function __construct( array $data = [] )
    {
        $this->data = $data;
    }


    /**
     * Returns the provider response ID.
     *
     * @return string|null Response ID or NULL if not reported
     */
    public function id() : ?string
    {
        return is_string( $this->data['id'] ?? null ) ? $this->data['id'] : null;
    }


    /**
     * Returns the model that produced the response.
     *
     * @return string|null Model name or NULL if not reported
     */
    public function model() : ?string
    {
        return is_string( $this->data['model'] ?? null ) ? $this->data['model'] : null;
    }


    /**
     * Returns the encrypted reasoning blocks for multi-turn continuity.
     *
     * @return array<int|string, mixed>|null Reasoning details or NULL if not reported
     */
    public function reasoningDetails() : ?array
    {
        return is_array( $this->data['reasoning_details'] ?? null ) ? $this->data['reasoning_details'] : null;
    }


    /**
     * Returns the extended thinking/reasoning output.
     *
     * @return string|null Thinking output or NULL if the model did not think
     */
    public function thinking() : ?string
    {
        return is_string( $this->data['thinking'] ?? null ) ? $this->data['thinking'] : null;
    }
}
