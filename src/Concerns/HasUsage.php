<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Usage information.
 */
trait HasUsage
{
    /** @var array<string, mixed> */
    private array $usage = [];


    /**
     * Returns the usage information.
     *
     * @return \Aimeos\Prisma\Values\Usage Usage information with typed token accessors and array access
     */
    public function usage() : \Aimeos\Prisma\Values\Usage
    {
        return new \Aimeos\Prisma\Values\Usage( $this->usage );
    }


    /**
     * Sets the usage information.
     *
     * @param float|null $used Used units
     * @param array<string, mixed> $more Additional usage information
     * @return static
     */
    public function withUsage( ?float $used, array $more = [] ) : static
    {
        $this->usage = ['used' => $used] + $more;
        return $this;
    }
}
