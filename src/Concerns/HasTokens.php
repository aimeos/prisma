<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Token limit handling for providers.
 */
trait HasTokens
{
    private ?int $maxTokens = null;
    private ?int $thinkingBudget = null;
    private ?bool $reasoning = null;


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
     * Enables or minimizes model reasoning using the provider's native format.
     *
     * Explicit request options take precedence over this provider-agnostic setting.
     * Providers that cannot control reasoning ignore it.
     *
     * @param bool $enabled TRUE to leave reasoning enabled, FALSE to minimize it
     * @return self
     */
    public function withReasoning( bool $enabled = true ) : self
    {
        $this->reasoning = $enabled;
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
     * Returns whether reasoning was explicitly enabled or minimized.
     */
    protected function reasoningEnabled() : ?bool
    {
        return $this->reasoning;
    }


    /**
     * Returns provider-specific request parameters for reasoning control.
     *
     * @return array<string, mixed>
     */
    protected function reasoningParams() : array
    {
        return [];
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
