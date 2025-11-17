<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;


/**
 * Embedding vector response.
 */
class VectorResponse
{
    use HasMeta, HasUsage;


    private array $vectors;


    final private function __construct()
    {
    }


    /**
     * Create a vector response instance.
     *
     * @param array $vector Embedding vector
     * @return self VectorResponse instance
     */
    public static function fromVectors( array $vectors ) : self
    {
        $instance = new self;
        $instance->vectors = $vectors;

        return $instance;
    }


    /**
     * Get the first embedding vector.
     *
     * @return array First embedding vector
     */
    public function first() : array
    {
        return $this->vectors[0] ?? [];
    }


    /**
     * Get the embedding vectors.
     *
     * @return array Embedding vectors
     */
    public function vectors() : array
    {
        return $this->vectors;
    }
}
