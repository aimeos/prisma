<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Usage information.
 */
trait HasUsage
{
    private array $usage = [];


    /**
     * Returns the usage information.
     *
     * @return array Usage information
     */
    public function usage() : array
    {
        return $this->usage;
    }


    /**
     * Sets the usage information.
     *
     * @param float|null $used Used units
     * @param array $more Additional usage information
     * @return self
     */
    public function withUsage( ?float $used, array $more = [] ) : self
    {
        $this->usage = ['used' => $used] + $more;
        return $this;
    }
}
