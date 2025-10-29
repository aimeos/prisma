<?php

namespace Aimeos\Prisma;

use Aimeos\Prisma\Contracts\Provider;


/**
 * Factory class for Prisma providers.
 */
class Prisma
{
    /**
     * Create a new provider by name.
     *
     * @param string $name Provider name in lower case
     * @param array $config Configuration parameter for the provider
     * @return Provider Provider instance
     */
    public static function using( string $name, array $config = [] ) : Provider
    {
        $classname = '\\Aimeos\\Prisma\\Providers\\' . ucfirst( $name );

        if( !class_exists( $classname ) ) {
            throw new \InvalidArgumentException( sprintf( 'Provider "%1$s" not found', $classname ) );
        }

        $provider = new $classname( $config );

        if( !( $provider instanceof Provider::class ) ) {
            throw new \InvalidArgumentException( sprintf( 'Provider "%1$s" does not implement "%2$s"', $classname, Provider::class ) );
        }

        return $provider;
    }
}
