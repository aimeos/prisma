<?php

namespace Aimeos\Prisma\Exceptions;


/**
 * Rate limit exception.
 *
 * Too many requests have been made in a short period of time.
 */
class RateLimitException extends PrismaException
{
    private ?int $retryAfter = null;


    /**
     * Returns the number of seconds to wait before retrying, if known.
     *
     * @return int|null Retry delay in seconds
     */
    public function retryAfter() : ?int
    {
        return $this->retryAfter;
    }


    /**
     * Sets the number of seconds to wait before retrying.
     *
     * @param int|null $seconds Retry delay in seconds
     * @return self Same exception instance
     */
    public function withRetryAfter( ?int $seconds ) : self
    {
        $this->retryAfter = $seconds;
        return $this;
    }
}
