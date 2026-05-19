<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Adapts raw name/description/schema/handler to the Adapter interface.
 */
class Prisma extends Base
{
    private \Aimeos\Prisma\Schema\Schema $schema;
    private string $name;
    private string $description;
    /** @var callable */
    private $fn;


    /**
     * Initializes the adapter with raw tool properties.
     *
     * @param string $name Tool name
     * @param string $description Tool description
     * @param \Aimeos\Prisma\Schema\Schema $schema Schema definition
     * @param callable $handler Tool execution handler
     */
    public function __construct( string $name, string $description, \Aimeos\Prisma\Schema\Schema $schema, callable $handler )
    {
        $this->description = $description;
        $this->schema = $schema;
        $this->name = $name;
        $this->fn = $handler;
    }


    protected function execute( array $arguments ) : mixed
    {
        return ( $this->fn )( $arguments );
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return $this->description;
    }


    /**
     * Returns the tool name.
     *
     * @return string Tool name
     */
    public function name() : string
    {
        return $this->name;
    }


    /**
     * Returns the schema definition for the tool parameters.
     *
     * @return \Aimeos\Prisma\Schema\Schema Schema definition
     */
    public function schema() : \Aimeos\Prisma\Schema\Schema
    {
        return $this->schema;
    }
}
