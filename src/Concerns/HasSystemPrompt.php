<?php

namespace Aimeos\Prisma\Concerns;


/**
 * System prompt handling for providers.
 */
trait HasSystemPrompt
{
    private ?string $systemPrompt = null;


    /**
     * Sets the system prompt for the LLM.
     *
     * @param string|null $prompt System prompt
     * @return self
     */
    public function withSystemPrompt( ?string $prompt ) : self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }


    /**
     * Returns the configured system prompt.
     *
     * @return string|null System prompt
     */
    protected function systemPrompt() : ?string
    {
        return $this->systemPrompt;
    }
}
