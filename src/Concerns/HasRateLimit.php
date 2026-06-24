<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Rate limit information from provider API responses.
 */
trait HasRateLimit
{
    private ?\Aimeos\Prisma\Values\RateLimit $rateLimit = null;


    /**
     * Returns the rate limit information.
     *
     * @return \Aimeos\Prisma\Values\RateLimit|null Rate limit info
     */
    public function rateLimit() : ?\Aimeos\Prisma\Values\RateLimit
    {
        return $this->rateLimit;
    }


    /**
     * Sets the rate limit information.
     *
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit info
     * @return static Response instance
     */
    public function withRateLimit( ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : static
    {
        // a null update keeps any rate limit already captured (e.g. from an eagerly opened stream)
        if( $rateLimit !== null ) {
            $this->rateLimit = $rateLimit;
        }

        return $this;
    }
}
