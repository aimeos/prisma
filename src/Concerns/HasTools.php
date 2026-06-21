<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Step;


/**
 * Tool handling for providers.
 */
trait HasTools
{
    /** The model decides whether to use tools. */
    const AUTO = 'auto';

    /** The model must use a tool. */
    const REQ = 'required';

    /** The model cannot use tools. */
    const NONE = 'none';


    private ?Concurrency $concurrency = null;
    private int $maxSteps = 25;
    private string $toolChoice = self::AUTO;

    /** @var array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> */
    private array $tools = [];

    /** @var array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> */
    private array $providerTools = [];


    /**
     * Sets the concurrency strategy for tool execution.
     *
     * @param Concurrency $concurrency Concurrency strategy
     * @return self
     */
    public function withConcurrency( Concurrency $concurrency ) : self
    {
        $this->concurrency = $concurrency;
        return $this;
    }


    /**
     * Sets the maximum number of tool execution steps.
     *
     * @param int $steps Maximum number of steps (minimum 1)
     * @return self
     */
    public function withMaxSteps( int $steps ) : self
    {
        $this->maxSteps = max( 1, $steps );
        return $this;
    }


    /**
     * Sets the tool choice strategy.
     *
     * @param string $choice Tool choice (use AUTO, REQ, NONE constants)
     * @return self
     */
    public function withToolChoice( string $choice ) : self
    {
        $this->toolChoice = $choice;
        return $this;
    }


    /**
     * Sets the tools available for the LLM.
     *
     * Accepts Adapter instances for custom tools and Provider instances
     * for built-in provider tools (e.g. web search, code execution).
     *
     * @param array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> $tools Tool definitions
     * @return self
     * @throws \InvalidArgumentException If a tool doesn't implement Adapter
     */
    public function withTools( array $tools ) : self
    {
        $this->tools = [];
        $this->providerTools = [];

        foreach( $tools as $tool )
        {
            if( $tool instanceof \Aimeos\Prisma\Tools\Adapter\Provider ) {
                $this->providerTools[] = $tool;
            } elseif( $tool instanceof \Aimeos\Prisma\Tools\Adapter\Adapter ) {
                $this->tools[] = $tool;
            } else {
                throw new \InvalidArgumentException( sprintf( 'Tool must implement Adapter, got %s', get_debug_type( $tool ) ) );
            }
        }

        return $this;
    }


    /**
     * Returns the concurrency instance.
     *
     * Defaults to sequential execution. Use withConcurrency() to opt into a
     * different strategy (e.g. forking) explicitly.
     *
     * @return Concurrency Concurrency instance
     */
    protected function concurrency() : Concurrency
    {
        if( !$this->concurrency )
        {
            $this->concurrency = new \Aimeos\Prisma\Tools\Concurrency\Sequential();
        }

        return $this->concurrency;
    }


    /**
     * Returns the maximum number of tool execution steps.
     *
     * @return int Maximum steps
     */
    protected function maxSteps() : int
    {
        return $this->maxSteps;
    }


    /**
     * Returns the configured provider tools.
     *
     * @return array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> Provider tool definitions
     */
    protected function providerTools() : array
    {
        return $this->providerTools;
    }


    /**
     * Returns the tool_choice parameter for the API request.
     *
     * @return string Tool choice value
     */
    protected function toolChoice() : string
    {
        return $this->toolChoice;
    }


    /**
     * Returns the configured tools.
     *
     * @return array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> Tool instances
     */
    protected function tools() : array
    {
        return $this->tools;
    }
}
