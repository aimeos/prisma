<?php

namespace Aimeos\Prisma\Tools\Concurrency;

use Aimeos\Prisma\Tools\Step;


/**
 * Executes tool calls in parallel using pcntl_fork with socket IPC.
 *
 * Each tool call runs in a forked child process. Results are passed back
 * to the parent via Unix socket pairs. Falls back to sequential execution
 * for individual calls where forking or socket creation fails.
 */
class Fork implements Concurrency
{
    /**
     * Executes tool calls in parallel using process forking.
     *
     * @param array<int, Step> $steps Steps to execute
     * @return array<int, Step> Executed steps
     */
    public function run( array $steps ) : array
    {
        if( count( $steps ) < 2 ) {
            return ( new Sequential )->run( $steps );
        }

        [$children, $fallback] = $this->fork( $steps );

        $this->collect( $children );

        ( new Sequential )->run( $fallback );

        return $steps;
    }


    /**
     * Forks child processes for each tool step.
     *
     * @param array<int, Step> $steps Steps to execute
     * @return array{0: array<int, array{pid: int, socket: \Socket, step: Step}>, 1: array<int, Step>}
     */
    private function fork( array $steps ) : array
    {
        /** @var array<int, array{pid: int, socket: \Socket, step: Step}> $children */
        $children = [];
        $fallback = [];

        foreach( $steps as $step )
        {
            $tool = $step->tool();

            if( !$tool ) {
                $fallback[] = $step;
                continue;
            }

            $pair = [];

            if( !socket_create_pair( AF_UNIX, SOCK_STREAM, 0, $pair ) ) {
                $fallback[] = $step;
                continue;
            }

            /** @var \Socket $parentSocket */
            $parentSocket = $pair[0];
            /** @var \Socket $childSocket */
            $childSocket = $pair[1];
            $pid = pcntl_fork();

            if( $pid === -1 )
            {
                socket_close( $parentSocket );
                socket_close( $childSocket );
                $fallback[] = $step;
                continue;
            }

            if( $pid === 0 )
            {
                socket_close( $parentSocket );

                // Prefix the result with a marker byte so the parent can tell an
                // empty tool result apart from a child that died without writing.
                $data = "\x01" . ( $tool )( $step->arguments() );
                socket_write( $childSocket, $data, strlen( $data ) );
                socket_close( $childSocket );

                // @codeCoverageIgnoreStart
                exit( 0 );
                // @codeCoverageIgnoreEnd
            }

            socket_close( $childSocket );
            $children[] = ['pid' => $pid, 'socket' => $parentSocket, 'step' => $step];
        }

        return [$children, $fallback];
    }


    /**
     * Collects results from forked child processes.
     *
     * @param array<int, array{pid: int, socket: \Socket, step: Step}> $children Forked children
     */
    private function collect( array $children ) : void
    {
        foreach( $children as $child )
        {
            $data = '';

            while( ( $chunk = @socket_read( $child['socket'], 65536 ) ) !== false && $chunk !== '' ) {
                $data .= $chunk;
            }

            socket_close( $child['socket'] );
            pcntl_waitpid( $child['pid'], $status );

            $step = $child['step'];

            if( $data === '' ) {
                $step->complete( 'Error: Tool process failed' );
            } else {
                $step->complete( substr( $data, 1 ) );
            }
        }
    }
}
