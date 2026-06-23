<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\VectorResponse;


interface Vectorize
{
    /**
     * Creates embedding vectors of the texts' content.
     *
     * @param array<int, string> $texts List of input texts
     * @param int|null $size Size of the resulting vector or null for provider default
     * @param array<string, mixed> $options Provider specific options
     * @return VectorResponse Response vector object
     */
    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse;
}
