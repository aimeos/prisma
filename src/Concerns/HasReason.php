<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Finish reason from provider API responses.
 */
trait HasReason
{
    /** The model finished normally (reached a natural end or stop sequence). */
    const STOP = 'stop';

    /** Output was truncated because it hit the max token limit. */
    const LENGTH = 'length';

    /** The model stopped to request tool calls; returned when maxSteps is exhausted mid-loop. */
    const TOOL = 'tool';

    /** Output was blocked or truncated by a safety/content filter. */
    const CONTENT = 'content';

    /** The provider returned an error during generation. */
    const ERROR = 'error';

    /** The provider returned an unrecognized finish reason. */
    const UNKNOWN = 'unknown';


    private ?string $reason = null;


    /**
     * Returns the finish reason from the final API response.
     *
     * @return string|null Finish reason (e.g. 'stop', 'tool', 'length', 'content', 'error', 'unknown')
     */
    public function reason() : ?string
    {
        return $this->reason;
    }


    /**
     * Sets the finish reason.
     *
     * @param string $reason Finish reason (use class constants)
     * @return static Response instance
     */
    public function withReason( string $reason ) : static
    {
        $this->reason = $reason;
        return $this;
    }
}
