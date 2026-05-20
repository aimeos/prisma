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
}
