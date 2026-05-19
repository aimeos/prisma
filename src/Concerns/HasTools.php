<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Step;


/**
 * Tool handling for providers.
 */
trait HasTools
{
    private ?Concurrency $concurrency = null;
    private int $maxSteps = PHP_INT_MAX;
    private string $toolChoice = 'auto';

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
     * @param string $choice Tool choice: 'auto', 'required', or 'none'
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
     * Returns the concurrency instance, auto-detecting the best strategy.
     *
     * @return Concurrency Concurrency instance
     */
    protected function concurrency() : Concurrency
    {
        if( !$this->concurrency )
        {
            $this->concurrency = function_exists( 'pcntl_fork' ) && function_exists( 'socket_create_pair' )
                ? new \Aimeos\Prisma\Tools\Concurrency\Fork()
                : new \Aimeos\Prisma\Tools\Concurrency\Sequential();
        }

        return $this->concurrency;
    }


    /**
     * Executes tool calls and collects results.
     *
     * Filters out unknown, provider, and exhausted tools, then delegates
     * valid calls to the configured Concurrency strategy for execution.
     *
     * @param array<int, array<string, mixed>> $toolCalls Tool calls from the model
     * @return array<int, Step> Tool execution results
     */
    protected function execTools( array $toolCalls ) : array
    {
        $toolMap = [];

        foreach( $this->tools() as $tool ) {
            $toolMap[$tool->name()] = $tool;
        }

        $results = [];
        $consumed = [];

        /** @var array<int, Step> $concurrent */
        $concurrent = [];
        /** @var array<int, Step> $sequential */
        $sequential = [];

        foreach( $toolCalls as $call )
        {
            /** @var array{id?: string|null, name: string, arguments: array<string, mixed>} $call */
            $name = $call['name'];
            $tool = $toolMap[$name] ?? null;

            if( !$tool ) {
                continue;
            }

            $used = $consumed[$name] ?? 0;

            if( $tool->counter() - $used <= 0 ) {
                $step = new Step( $call['id'] ?? null, $name, $call['arguments'] );
                $step->complete( sprintf( 'Error: Tool "%s" has exhausted its maximum number of calls', $name ) );
                $results[] = $step;
                continue;
            }

            $consumed[$name] = $used + 1;
            $step = new Step( $call['id'] ?? null, $name, $call['arguments'], $tool );

            if( $tool->isConcurrent() ) {
                $concurrent[] = $step;
            } else {
                $sequential[] = $step;
            }
        }

        $this->concurrency()->run( $concurrent );
        ( new \Aimeos\Prisma\Tools\Concurrency\Sequential() )->run( $sequential );

        // Sync counters for concurrent tools only (child fork doesn't affect parent).
        // Sequential tools already decrement in __invoke() within the same process.
        $concurrentConsumed = [];
        foreach( $concurrent as $step )
        {
            $name = $step->name();
            $concurrentConsumed[$name] = ( $concurrentConsumed[$name] ?? 0 ) + 1;
        }

        foreach( $concurrentConsumed as $name => $count )
        {
            $tool = $toolMap[$name];
            $remaining = $tool->counter() - $count;
            $tool->max( max( 0, $remaining ) );
        }

        return array_merge( $results, $concurrent, $sequential );
    }


    /**
     * Builds mapped provider tools from a provider tool map.
     *
     * @param array<string, array<string, mixed>> $map Provider tool map
     * @return array<int, array<string, mixed>> Formatted provider tools
     */
    protected function mapTools( array $map ) : array
    {
        $tools = [];

        foreach( $this->providerTools() as $tool )
        {
            if( isset( $map[$tool->name()] ) )
            {
                $entry = $map[$tool->name()];
                $optionsSpec = $entry['options'] ?? [];
                unset( $entry['options'] );

                $allowed = [];
                $renames = [];

                /** @var array<string|int, string> $optionsSpec */

                foreach( $optionsSpec as $key => $value )
                {
                    if( is_int( $key ) ) {
                        $allowed[] = $value;
                    } else {
                        $allowed[] = $key;
                        $renames[$key] = $value;
                    }
                }

                /** @var array<int, string> $allowed */
                $options = array_intersect_key( $tool->options(), array_flip( $allowed ) );

                foreach( $renames as $from => $to )
                {
                    if( isset( $options[$from] ) ) {
                        $options[$to] = $options[$from];
                        unset( $options[$from] );
                    }
                }

                /** @var array<string, mixed> $merged */
                $merged = array_merge( $entry, $options );
                $tools[] = $merged;
            }
        }

        return $tools;
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
