<?php

namespace Aimeos\Prisma\Contracts;


interface Provider
{
    /**
     * Create a new provider instance with the given configuration.
     *
     * @param array<string, mixed> $config Configuration options for the provider.
     * @return Provider
     */
    public function __construct( array $config );
}
