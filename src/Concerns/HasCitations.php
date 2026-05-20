<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Citation information from provider API responses.
 */
trait HasCitations
{
    /** @var array<int, array<string, mixed>> */
    private array $citations = [];


    /**
     * Returns the citations.
     *
     * @return array<int, array<string, mixed>> Citations
     */
    public function citations() : array
    {
        return $this->citations;
    }


    /**
     * Sets the citations.
     *
     * @param array<int, array<string, mixed>> $citations Citations
     * @return static
     */
    public function withCitations( array $citations ) : static
    {
        $this->citations = $citations;
        return $this;
    }
}
