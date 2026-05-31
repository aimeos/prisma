<?php

namespace Aimeos\Prisma;

use Aimeos\Prisma\Tools\Adapter\Adapter;
use Aimeos\Prisma\Tools\Adapter\Laravel;
use Aimeos\Prisma\Tools\Adapter\Prisma;
use Aimeos\Prisma\Tools\Adapter\Provider;
use Aimeos\Prisma\Tools\Adapter\Symfony;


/**
 * Factory for creating tool adapters from different sources.
 */
class Tools
{
    /**
     * Creates a Laravel adapter from a Laravel AI or MCP server tool.
     *
     * @param object|string $tool Laravel AI/MCP tool object or its fully qualified class name
     * @return Adapter Adapter instance
     */
    public static function laravel( object|string $tool ) : Adapter
    {
        return new Laravel( $tool );
    }


    /**
     * Creates a Prisma adapter from a name, description, schema and handler.
     *
     * @param string $name Tool name
     * @param string $description Tool description
     * @param Schema\Schema $schema Schema definition
     * @param callable $handler Tool execution handler
     * @return Adapter Adapter instance
     */
    public static function make( string $name, string $description, Schema\Schema $schema, callable $handler ) : Adapter
    {
        return new Prisma( $name, $description, $schema, $handler );
    }



    /**
     * Creates a built-in provider tool reference.
     *
     * @param string $name Provider tool name (e.g. 'web_search', 'code_execution')
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
        return new Symfony( $tool, $name );
    }
}
