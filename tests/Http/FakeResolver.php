<?php

namespace Tests\Http;

use Aimeos\Prisma\Http\Resolver;


/**
 * Resolver without DNS lookups which counts its lookups.
 */
class FakeResolver extends Resolver
{
    public int $lookups = 0;


    protected function lookup( string $host ) : array
    {
        $this->lookups++;

        return match( true ) {
            $host === 'api.example.com' => ['93.184.215.14'],
            $host === 'multi.example.com' => ['93.184.215.14', '10.0.0.1', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'],
            default => [],
        };
    }
}
