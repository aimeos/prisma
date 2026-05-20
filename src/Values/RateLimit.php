<?php

namespace Aimeos\Prisma\Values;


/**
 * Rate limit information from provider API response headers.
 */
class RateLimit
{
    private readonly ?int $limit;
    private readonly ?int $remaining;
    private readonly ?string $reset;
    private readonly ?int $retryAfter;


    /**
     * Initializes the rate limit information.
     *
     * @param int|null $limit Request limit
     * @param int|null $remaining Remaining requests
     * @param string|null $reset Reset timestamp
     * @param int|null $retryAfter Retry after seconds
     */
    public function __construct( ?int $limit = null, ?int $remaining = null, ?string $reset = null, ?int $retryAfter = null )
    {
        $this->limit = $limit;
        $this->remaining = $remaining;
        $this->reset = $reset;
        $this->retryAfter = $retryAfter;
    }


    /**
     * Returns the request limit.
     *
     * @return int|null Request limit
     */
    public function limit() : ?int
    {
        return $this->limit;
    }


    /**
     * Returns the remaining requests.
     *
     * @return int|null Remaining requests
     */
    public function remaining() : ?int
    {
        return $this->remaining;
    }


    /**
     * Returns the reset timestamp.
     *
     * @return string|null Reset timestamp
     */
    public function reset() : ?string
    {
        return $this->reset;
    }


    /**
     * Returns the retry after seconds.
     *
     * @return int|null Retry after seconds
     */
    public function retryAfter() : ?int
    {
        return $this->retryAfter;
    }
}
