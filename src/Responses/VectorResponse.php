<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;


/**
 * Embedding vector response.
 */
class VectorResponse implements \JsonSerializable
{
    use HasMeta, HasUsage;


    /** @var array<int, array<int, float>|null> */
    private array $vectors;


    /**
     * Get the first embedding vector.
     *
     * @return array<int, float>|null First embedding vector
     */
    public function first() : ?array
    {
        return $this->vectors[0] ?? null;
    }


    /**
     * Create a vector response instance.
     *
     * @param array<int, array<int, float>|null> $vectors Embedding vectors
     * @return self VectorResponse instance
     */
    public static function fromVectors( array $vectors ) : self
    {
        $instance = new self;
        $instance->vectors = $vectors;

        return $instance;
    }


    /**
     * Allows iterating over the list of available vectors.
     *
     * @return \ArrayIterator<int, array<int, float>|null> Traversable list of vectors
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator( $this->vectors );
    }


    /**
     * Returns the response as a plain array for serialization.
     *
     * @return array<string, mixed> Response data
     */
    public function jsonSerialize() : array
    {
        return [
            'vectors' => $this->vectors,
            'usage' => $this->usage(),
            'meta' => $this->meta(),
        ];
    }


    /**
     * Get the embedding vectors.
     *
     * @return array<int, array<int, float>|null> Embedding vectors
     */
    public function vectors() : array
    {
        return $this->vectors;
    }


    final private function __construct()
    {
    }
}
