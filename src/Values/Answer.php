<?php

namespace Aimeos\Prisma\Values;


/**
 * Typed answer to a decision question.
 *
 * Behaves like the answer array (array access, iteration, count and JSON) while adding typed
 * accessors for the answer value, its probability distribution and the confidence. Providers
 * pass the answer value as "value" and keep their own keys for array access.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class Answer implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    use \Aimeos\Prisma\Concerns\AsArray;


    /**
     * Initializes the answer.
     *
     * @param array<string, mixed> $data Raw answer map
     */
    public function __construct( array $data = [] )
    {
        $this->data = $data;
    }


    /**
     * Returns how certain the model is about the answer.
     *
     * @return float|null Confidence between 0 and 1 or NULL if not reported
     */
    public function confidence() : ?float
    {
        return is_numeric( $this->data['confidence'] ?? null ) ? (float) $this->data['confidence'] : null;
    }


    /**
     * Returns the probability of each possible outcome.
     *
     * @return array<string, float> Map of outcome to probability, empty if not reported
     */
    public function probabilities() : array
    {
        return is_array( $this->data['probabilities'] ?? null ) ? $this->data['probabilities'] : [];
    }


    /**
     * Returns the answer type.
     *
     * @return string|null Provider specific answer type or NULL if not reported
     */
    public function type() : ?string
    {
        return is_string( $this->data['type'] ?? null ) ? $this->data['type'] : null;
    }


    /**
     * Returns the answer value.
     *
     * @return float|string|null Selected outcome, probability or rating depending on the question type, NULL if not reported
     */
    public function value() : float|string|null
    {
        $value = $this->data['value'] ?? null;

        return match( true ) {
            is_string( $value ) => $value,
            is_numeric( $value ) => (float) $value,
            default => null,
        };
    }
}
