<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Adapts Laravel AI/MCP tools to the Adapter interface.
 */
class Laravel extends Base
{
    private object $tool;


    /**
     * Initializes the adapter with a Laravel AI/MCP tool.
     *
     * @param object $tool Laravel AI/MCP tool instance
     * @throws \InvalidArgumentException If the object is not a valid Laravel AI/MCP tool
     */
    public function __construct( object $tool )
    {
        if( !method_exists( $tool, 'name' ) || !method_exists( $tool, 'description' ) || !method_exists( $tool, 'toArray' ) ) {
            throw new \InvalidArgumentException( sprintf( 'Object of class "%s" is not a valid Laravel AI/MCP tool', get_class( $tool ) ) );
        }

        $this->tool = $tool;
    }


    protected function execute( array $arguments ) : mixed
    {
        $class = '\Laravel\Mcp\Server\Tool';

        if( class_exists( $class ) && $this->tool instanceof $class ) {
            return unwrap( app()->call( [$this->tool, 'handle'], ['request' => new \Laravel\Mcp\Request( $arguments )] ) ); // @phpstan-ignore class.notFound, function.notFound, function.notFound
        } elseif( method_exists( $this->tool, '__invoke' ) ) {
            return ( $this->tool )( $arguments );
        } elseif( method_exists( $this->tool, 'handle' ) ) {
            return $this->tool->handle( $arguments );
        }

        return '';
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return (string) $this->tool->description(); // @phpstan-ignore method.notFound
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return (string) $this->tool->name(); // @phpstan-ignore method.notFound
    }


    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema
    {
        /** @var array<string, mixed> $arr */
        $arr = $this->tool->toArray(); // @phpstan-ignore method.notFound

        // Laravel MCP/AI tools nest the JSON Schema under "inputSchema" (or "parameters");
        // fall back to the raw array for tools that already return a bare schema.
        $schema = $arr['inputSchema'] ?? $arr['parameters'] ?? $arr;

        return \Aimeos\Prisma\Schema\Schema::fromArray( $this->name(), $schema );
    }
}
