<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Meta information.
 */
trait HasMeta
{
    /** @var array<string, mixed> */
    private array $meta = [];


    /**
     * Returns the meta information.
     *
     * @return array<string, mixed> Meta information
     */
    public function meta() : array
    {
        return $this->meta;
    }


    /**
     * Sets the meta information.
     *
     * @param array<string, mixed> $meta Meta information
     * @return static
     */
    public function withMeta( array $meta ) : static
    {
        $this->meta = $meta;
        return $this;
    }
}
