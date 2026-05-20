<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Citation information from provider API responses.
 */
trait HasCitations
{
    /** @var array<int, \Aimeos\Prisma\Values\Citation> */
    private array $citations = [];


    /**
     * Returns the citations.
     *
     * @return array<int, \Aimeos\Prisma\Values\Citation> Citations
     */
    public function citations() : array
    {
        return $this->citations;
    }


    /**
     * Sets the citations.
     *
     * @param array<int, \Aimeos\Prisma\Values\Citation> $citations Citations
     * @return static
     */
    public function withCitations( array $citations ) : static
    {
        $this->citations = $citations;
        return $this;
    }
}
