<?php

namespace Tests;

use Aimeos\Prisma\Testing\InteractsWithPrisma;
use PHPUnit\Framework\Assert;


trait MakesPrismaRequests
{
    use InteractsWithPrisma;


    /**
     * Assert that a Prisma request matching the callback was sent.
     *
     * The callback may run its own assertions and return nothing; only an explicit
     * `false` return marks a request as non-matching.
     *
     * @param callable $callback Callback to validate the request and options
     * @param string $message Error message
     * @return void
     */
    protected function assertPrismaRequest( callable $callback, string $message = '' ) : void
    {
        if( $this->requested( fn( $request, $options ) => $callback( $request, $options ) !== false ) === null ) {
            Assert::fail( $message ?: 'No matching Prisma request was sent.' );
        }
    }
}
