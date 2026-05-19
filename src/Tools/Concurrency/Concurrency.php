<?php

namespace Aimeos\Prisma\Tools\Concurrency;

/**
 * Interface for tool call execution strategies.
 */
interface Concurrency
{
    /**
     * Executes tool calls and returns results.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Step> $steps Steps to execute
     * @return array<int, \Aimeos\Prisma\Tools\Step> Executed steps
     */
    public function run( array $steps ) : array;
}
