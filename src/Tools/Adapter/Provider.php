<?php

namespace Aimeos\Prisma\Tools\Adapter;


/**
 * Represents a built-in provider tool reference.
 */
class Provider extends Base
{
    private string $name;
    private ?\Aimeos\Prisma\Schema\Schema $schema = null;


    /**
     * Initializes the adapter with a provider tool name.
     *
     * @param string $name Provider tool name
     */
    public function __construct( string $name )
    {
        $this->name = $name;
    }


    /**
     * Returns the tool description.
     *
     * @return string Tool description
     */
    public function description() : string
    {
        return '';
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
        return $this->schema ??= \Aimeos\Prisma\Schema\Schema::fromArray( $this->name, [] );
    }


    protected function execute( array $arguments ) : mixed
    {
        return '';
    }
}
