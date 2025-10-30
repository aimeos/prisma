<?php

namespace Aimeos\Prisma\Contracts;


interface Provider
{
    /**
     * Create a new provider instance with the given configuration.
     *
     * @param array<string, mixed> $config Configuration options for the provider.
     */
    public function __construct( array $config );


    /**
     * Ensures that the provider has implemented the method.
     *
     * @param string $method Method name
     * @return Provider
     * @throws \Aimeos\Prisma\Exceptions\NotImplemented
     */
    public function ensure( string $method ) : self;


    /**
     * Tests if the provider has implemented the method.
     *
     * @param string $method Method name
     * @return bool TRUE if implemented, FALSE if absent
     */
    public function has( string $method ) : bool;
}
