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
     * Thin wrapper around execStream() for the non-streaming tool loop: drains the
     * generator and returns the completed steps in the model's call order.
     *
     * @param array<int, array<string, mixed>> $toolCalls Tool calls from the model
     * @param array<string, int> $calls Remaining calls per tool name (by reference)
     * @return array<int, Step> Tool execution results
     */
    protected function execTools( array $toolCalls, array &$calls ) : array
    {
        $steps = $this->execStream( $toolCalls, $calls );

        foreach( $steps as $step ) {} // drain to execute the tools

        return $steps->getReturn();
    }


    /**
     * Executes tool calls and yields each step before and after execution.
     *
     * Filters out unknown, provider, and exhausted tools, then hands every runnable call to
     * the single configured Concurrency strategy (see concurrency()) in the model's call
     * order. Each step that runs is yielded once before execution (done() === false) and once
     * after (done() === true); steps that exhausted their limit or failed validation are
     * already complete and yielded only once. The completed steps are returned in the model's
     * call order via the generator return value so order-correlated providers (e.g. Gemini)
     * can rely on it.
     *
     * The call budget tracks the remaining calls per tool name for the current
     * generation. It is passed by reference so the budget survives across the
     * steps of one tool loop while resetting per top-level request, and it is
     * decremented inline once per executed call regardless of the outcome.
     *
     * @param array<int, array<string, mixed>> $toolCalls Tool calls from the model
     * @param array<string, int> $calls Remaining calls per tool name (by reference)
     * @return \Generator<int, Step, mixed, array<int, Step>> Steps before and after execution
     */
    protected function execStream( array $toolCalls, array &$calls ) : \Generator
    {
        $toolMap = [];

        foreach( $this->tools() as $tool ) {
            $toolMap[$tool->name()] = $tool;
        }

        // Keyed by the original call position so results can be returned in the
        // model's call order, which order-correlated providers (e.g. Gemini) rely on.
        /** @var array<int, Step> $steps */
        $steps = [];

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

            // Human-in-the-loop: a tool flagged with the "needs_approval" option is only
            // executed when the configured approval callback allows it; a denied call
            // returns an error to the model so it can choose a different action.
            if( ( $tool->options()['needs_approval'] ?? false ) && ( $approve = $this->toolApproval() )
                && !$approve( $name, $call['arguments'] ) ) {
                $steps[$idx] = $this->errorStep( $call, sprintf( 'Error: call to tool "%s" was denied', $name ) );
                continue;
            }

            // Decrement the budget before running the step so the count stays
            // authoritative regardless of the concurrency strategy used.
            $calls[$name] = $remaining - 1;
            $steps[$idx] = new Step( $call['id'] ?? null, $name, $call['arguments'], $tool );
        }

        // Order by the model's call position up front so the before- and after-execution
        // notifications are emitted in the same order even for out-of-order call indices.
        ksort( $steps );

        // Notify each step before it runs; steps that exhausted their call limit
        // are already complete and only get the post-execution notification below.
        foreach( $steps as $step )
        {
            if( !$step->done() ) { yield $step; }
        }

        // Hand every runnable step to the single configured executor in the model's call order.
        // The default Sequential runs them in order; a parallel strategy may run the steps flagged
        // concurrent (Step::tool()->isConcurrent()) in parallel and the rest in order.
        $this->concurrency()->run( array_values( array_filter( $steps, fn( Step $step ) => !$step->done() ) ) );

        $steps = array_values( $steps );

        foreach( $steps as $step ) {
            yield $step;
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
