<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Token limit handling for providers.
 */
trait HasTokens
{
    private ?int $maxTokens = null;
    private ?int $thinkingBudget = null;


    /**
     * Sets the maximum number of output tokens.
     *
     * @param int|null $tokens Maximum output tokens
     * @return self
     */
    public function withMaxTokens( ?int $tokens ) : self
    {
        $this->maxTokens = $tokens;
        return $this;
    }


    /**
     * Sets the thinking budget in tokens.
     *
     * @param int|null $budget Thinking budget tokens
     * @return self
     */
    public function withThinkingBudget( ?int $budget ) : self
    {
        $this->thinkingBudget = $budget;
        return $this;
    }


    /**
     * Returns the configured maximum output tokens.
     *
     * @return int|null Maximum output tokens
     */
    protected function maxTokens() : ?int
    {
        return $this->maxTokens;
    }


    /**
     * Returns the configured thinking budget.
     *
     * @return int|null Thinking budget tokens
     */
    protected function thinkingBudget() : ?int
    {
        return $this->thinkingBudget;
    }
}
