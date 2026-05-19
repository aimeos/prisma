<?php

namespace Aimeos\Prisma;

use Aimeos\Prisma\Tools\Adapter\Adapter;
use Aimeos\Prisma\Tools\Adapter\Laravel;
use Aimeos\Prisma\Tools\Adapter\Prisma;
use Aimeos\Prisma\Tools\Adapter\Provider;
use Aimeos\Prisma\Tools\Adapter\Symfony;


class Tools
{
    /**
     * Creates a Laravel adapter from a Laravel AI tool object.
     *
     * @param object $tool Laravel tool object
     * @return Adapter Adapter instance
     */
    public static function laravel( object $tool ) : Adapter
    {
        return new Laravel( $tool );
    }


    /**
     * Creates a Prisma adapter from a name, description, handler and schema.
     *
     * @param Schema\Schema $schema Schema definition
     * @return Adapter Adapter instance
     */
    public static function make( string $name, string $description, Schema\Schema $schema, callable $handler ) : Adapter
    {
        return new Prisma( $name, $description, $schema, $handler );
    }



    /**
     * Creates a built-in provider tool reference.
     *
     * @return Adapter Adapter instance
     */
    public static function provider( string $name ) : Adapter
    {
        return new Provider( $name );
    }


    /**
     * Creates a Symfony adapter from a class with an #[AsTool] attribute.
     *
     * @param object|string $tool Symfony tool object or class name
     * @param string|null $name Optional tool name to select a specific #[AsTool] attribute
     * @return Adapter Adapter instance
     */
    public static function symfony( object|string $tool, ?string $name = null ) : Adapter
    {
        // @phpstan-ignore argument.type
        $ref = new \ReflectionClass( $tool );
        $attrs = array_filter( $ref->getAttributes(), fn( $a ) => str_ends_with( $a->getName(), 'AsTool' ) );

        if( empty( $attrs ) ) {
            throw new \InvalidArgumentException( sprintf( 'Class "%s" has no #[AsTool] attribute', is_object( $tool ) ? get_class( $tool ) : $tool ) );
        }

        foreach( $attrs as $attr )
        {
            /** @var array<string, string> $args */
            $args = $attr->getArguments();
            $toolName = $args['name'] ?? $args[0] ?? '';

            if( $name === null || $toolName === $name ) {
                $instance = is_object( $tool ) ? $tool : $ref->newInstance();
                $description = $args['description'] ?? $args[1] ?? '';
                $method = $args['method'] ?? $args[2] ?? '__invoke';

                return new Symfony( $instance, $toolName, $description, $method );
            }
        }

        throw new \InvalidArgumentException( sprintf( 'Class "%s" has no #[AsTool] attribute with name "%s"', is_object( $tool ) ? get_class( $tool ) : $tool, $name ) );
    }
}
