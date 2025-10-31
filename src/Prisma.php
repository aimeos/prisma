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
    private $type;


    /**
     * Creates a new Prisma factory instance for the specified provider type.
     *
     * @param string $type Provider type
     */
    public function __construct( string $type )
    {
        $this->type = $type;
    }


    /**
     * Create a new provider by name.
     *
     * @param string $name Provider name in lower case
     * @param array $config Configuration parameter for the provider
     * @return Provider Provider instance
     */
    public function using( string $name, array $config = [] ) : Provider
    {
        $classname = '\\Aimeos\\Prisma\\Providers\\' . ucfirst( $this->type ) . '\\' . ucfirst( $name );

        if( !class_exists( $classname ) ) {
            throw new NotExistsException( sprintf( 'Provider "%1$s" not found', $classname ) );
        }

        $provider = new $classname( $config );

        if( !( $provider instanceof Provider ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" does not implement "%2$s"', $classname, Provider::class ) );
        }

        return $provider;
    }


    /**
     * Creates a new Prisma factory instance for image generation.
     */
    public static function image() : self
    {
        return new self( 'image' );
    }
}
