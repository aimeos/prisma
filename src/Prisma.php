<?php

namespace Aimeos\Prisma;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotExistsException;
use Aimeos\Prisma\Exceptions\NotImplementedException;


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
            throw new NotExistsException( sprintf( 'Provider "%1$s" not found', $classname ) );
        }

        $provider = new $classname( $config );

        if( !( $provider instanceof Provider ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" does not implement "%2$s"', $classname, Provider::class ) );
        }

        return $provider;
    }
}
