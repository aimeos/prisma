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
     * @return \Aimeos\Prisma\Values\Meta Meta information with typed accessors and array access
     */
    public function meta() : \Aimeos\Prisma\Values\Meta
    {
        return new \Aimeos\Prisma\Values\Meta( $this->meta );
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
