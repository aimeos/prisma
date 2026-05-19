<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Rate limit information from provider API responses.
 */
trait HasRateLimit
{
    /** @var array<string, mixed> */
    private array $rateLimit = [];


    /**
     * Returns the rate limit information.
     *
     * @return array<string, mixed> Rate limit info (limit, remaining, reset)
     */
    public function rateLimit() : array
    {
        return $this->rateLimit;
    }


    /**
     * Sets the rate limit information.
     *
     * @param array<string, mixed> $rateLimit Rate limit info
     * @return static Response instance
     */
    public function withRateLimit( array $rateLimit ) : static
    {
        $this->rateLimit = $rateLimit;
        return $this;
    }
}
