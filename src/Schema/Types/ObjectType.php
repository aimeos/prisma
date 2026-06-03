<?php

namespace Aimeos\Prisma\Schema\Types;


class ObjectType extends Type
{
    protected ?bool $additionalProperties = null;
    /** @var array<string, Type> */
    protected array $properties = [];
    /** @var array<string, Type> */
    protected array $defs = [];


    /**
     * Creates a new object type with the given properties.
     *
     * @param array<string, Type> $properties Object properties
     */
    public function __construct( array $properties = [] )
    {
        $this->properties = $properties;
    }


    /**
     * Creates an object type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $required = is_array( $def['required'] ?? null ) ? $def['required'] : [];
        $props = is_array( $def['properties'] ?? null ) ? $def['properties'] : [];
        $properties = [];

        foreach( $props as $name => $propDef )
        {
            if( !is_string( $name ) || !is_array( $propDef ) ) {
                continue;
            }

            /** @var array<string, mixed> $propDef */
            $prop = Type::fromArray( $propDef );
            $prop->required = in_array( $name, $required ) ?: null;
            $properties[$name] = $prop;
        }

        $defs = is_array( $def['$defs'] ?? null ) ? $def['$defs'] : [];
        $definitions = [];

        foreach( $defs as $name => $defDef )
        {
            if( !is_string( $name ) || !is_array( $defDef ) ) {
                continue;
            }

            /** @var array<string, mixed> $defDef */
            $definitions[$name] = Type::fromArray( $defDef );
        }

        $type = new self( $properties );
        $type->defs = $definitions;
        $type->additionalProperties = is_bool( $def['additionalProperties'] ?? null ) ? $def['additionalProperties'] : null;
        $type->default = is_array( $def['default'] ?? null ) ? $def['default'] : null;
        return $type;
    }


    /**
     * Adds a reusable definition referenced via "$ref".
     */
    public function def( string $name, Type $type ) : static
    {
        $this->defs[$name] = $type;
        return $this;
    }


    /**
     * Sets the default value.
     *
     * @param array<string, mixed> $value Default object value
     */
    public function default( array $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    /**
     * Returns the type as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema type definition
     */
    public function toArray() : array
    {
        $required = array_keys( array_filter(
            $this->properties,
            fn( Type $p ) => $p->required === true
        ) );

        return array_filter( parent::toArray() + [
            'properties' => array_map( fn( Type $p ) => $p->toArray(), $this->properties ) ?: null,
            'required' => $required ?: null,
            'additionalProperties' => $this->additionalProperties,
            '$defs' => array_map( fn( Type $t ) => $t->toArray(), $this->defs ) ?: null,
        ], fn( $v ) => $v !== null );
    }


    public function withoutAdditionalProperties() : static
    {
        $this->additionalProperties = false;
        return $this;
    }


    protected static function typeName() : string
    {
        return 'object';
    }
}
