<?php

namespace Aimeos\Prisma\Tools\Concurrency;

use Aimeos\Prisma\Tools\Step;


/**
 * Executes tool calls sequentially.
 */
class Sequential implements Concurrency
{
    /**
     * Executes tool calls one after another.
     *
     * @param array<int, Step> $steps Steps to execute
     * @return array<int, Step> Executed steps
     */
    public function run( array $steps ) : array
    {
        foreach( $steps as $step )
        {
            if( $tool = $step->tool() )
            {
                $step->complete( $tool( $step->arguments() ) );
            }
        }

        return $steps;
    }
}
