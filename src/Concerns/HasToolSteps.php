<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Tools\Step;


/**
 * Tool step information (calls paired with results).
 */
trait HasToolSteps
{
    /** @var array<int, Step> */
    private array $steps = [];


    /**
     * Returns the tool steps (call + result pairs) from all iterations.
     *
     * @return array<int, Step> Tool steps
     */
    public function steps() : array
    {
        return $this->steps;
    }


    /**
     * Sets the tool steps.
     *
     * @param array<int, Step> $steps Tool steps
     * @return static Response instance
     */
    public function withSteps( array $steps ) : static
    {
        $this->steps = $steps;
        return $this;
    }
}
