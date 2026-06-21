<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Step;


/**
 * Calling tools from providers.
 */
trait CallsTools
{
    /**
     * Executes tool calls and collects results.
     *
     * Filters out unknown, provider, and exhausted tools, then delegates
     * valid calls to the configured Concurrency strategy for execution.
     *
     * The call budget tracks the remaining calls per tool name for the current
     * generation. It is passed by reference so the budget survives across the
     * steps of one tool loop while resetting per top-level request, and it is
     * decremented inline once per executed call regardless of the outcome.
     *
     * @param array<int, array<string, mixed>> $toolCalls Tool calls from the model
     * @param array<string, int> $calls Remaining calls per tool name (by reference)
     * @param callable|null $callback Step notifier invoked before execution (done() === false) and after (done() === true)
     * @return array<int, Step> Tool execution results
     */
    protected function execTools( array $toolCalls, array &$calls, ?callable $callback = null ) : array
    {
        $toolMap = [];

        foreach( $this->tools() as $tool ) {
            $toolMap[$tool->name()] = $tool;
        }

        // Keyed by the original call position so results can be returned in the
        // model's call order, which order-correlated providers (e.g. Gemini) rely on.
        /** @var array<int, Step> $steps */
        $steps = [];
        /** @var array<int, Step> $concurrent */
        $concurrent = [];
        /** @var array<int, Step> $sequential */
        $sequential = [];

        foreach( $toolCalls as $idx => $call )
        {
            /** @var array{id?: string|null, name: string, arguments: array<string, mixed>} $call */
            $name = $call['name'];
            $tool = $toolMap[$name] ?? null;

            if( !$tool ) {
                continue;
            }

            $remaining = $calls[$name] ?? $tool->limit();

            if( $remaining <= 0 ) {
                $steps[$idx] = $this->errorStep( $call, sprintf( 'Error: Tool "%s" has exhausted its maximum number of calls', $name ) );
                continue;
            }

            // Reject model-supplied arguments that violate the tool schema before the
            // handler runs; the error is returned so the model can correct and retry.
            if( $errors = $tool->schema()->validate( $call['arguments'] ) ) {
                $steps[$idx] = $this->errorStep( $call, sprintf( 'Error: invalid arguments for tool "%s" - %s', $name, implode( '; ', $errors ) ) );
                continue;
            }

            // Decrement the budget before running the step so the count stays
            // authoritative regardless of the concurrency strategy used.
            $calls[$name] = $remaining - 1;
            $step = new Step( $call['id'] ?? null, $name, $call['arguments'], $tool );
            $steps[$idx] = $step;

            if( $tool->isConcurrent() ) {
                $concurrent[] = $step;
            } else {
                $sequential[] = $step;
            }
        }

        if( $callback )
        {
            // Notify each step before it runs; steps that exhausted their call limit
            // are already complete and only get the post-execution notification below.
            foreach( $steps as $step )
            {
                if( !$step->done() ) { $callback( $step ); }
            }
        }

        $this->concurrency()->run( $concurrent );
        ( new \Aimeos\Prisma\Tools\Concurrency\Sequential() )->run( $sequential );

        ksort( $steps );
        $steps = array_values( $steps );

        if( $callback )
        {
            foreach( $steps as $step ) { $callback( $step ); }
        }

        return $steps;
    }


    /**
     * Builds mapped provider tools from a provider tool map.
     *
     * @param array<string, array<string, mixed>> $map Provider tool map
     * @return array<int, array<string, mixed>> Formatted provider tools
     */
    protected function mapProviderTools( array $map ) : array
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
     * Builds a completed error step for a call that will not be executed.
     *
     * @param array{id?: string|null, name: string, arguments: array<string, mixed>} $call Tool call
     * @param string $message Error message returned to the model
     * @return Step Completed error step
     */
    private function errorStep( array $call, string $message ) : Step
    {
        $step = new Step( $call['id'] ?? null, $call['name'], $call['arguments'] );
        $step->complete( $message );

        return $step;
    }
}
