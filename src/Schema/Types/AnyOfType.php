<?php

namespace Aimeos\Prisma\Schema\Types;


class AnyOfType extends Type
{
    /** @var array<int, Type> */
    protected array $types = [];


    /**
     * Creates a new anyOf type from the given list of types.
     *
     * @param array<int, Type> $types Allowed types for the value
     */
    public function __construct( array $types = [] )
    {
        $this->types = array_values( $types );
    }


    /**
     * Creates an anyOf type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $variants = is_array( $def['anyOf'] ?? null ) ? $def['anyOf'] : [];
        $types = [];

        foreach( $variants as $variant )
        {
            if( is_array( $variant ) ) {
                /** @var array<string, mixed> $variant */
                $types[] = Type::fromArray( $variant );
            }
        }

        $type = new self( $types );
        $type->title = is_string( $def['title'] ?? null ) ? $def['title'] : null;
        $type->description = is_string( $def['description'] ?? null ) ? $def['description'] : null;
        $type->default = $def['default'] ?? null;

        return $type;
    }


    /**
     * Adds another allowed type to the anyOf.
     */
    public function add( Type $type ) : static
    {
        $this->types[] = $type;
        return $this;
    }


    /**
     * Returns the type as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema type definition
     */
    public function toArray() : array
    {
        return array_filter( [
            'title' => $this->title,
            'description' => $this->description,
            'anyOf' => array_map( fn( Type $t ) => $t->toArray(), $this->types ) ?: null,
            'default' => $this->default,
        ], fn( $v ) => $v !== null );
    }


    /**
     * Validates the value against the allowed types, passing if any one matches.
     *
     * @param array<string, Type> $defs Reusable definitions for $ref resolution
     * @return array<int, string> Validation error messages
     */
    public function validate( mixed $data, array $defs = [], string $path = '' ) : array
    {
        foreach( $this->types as $type )
        {
            if( !$type->validate( $data, $defs, $path ) ) {
                return [];
            }
        }

        return [$this->label( $path ) . ' does not match any allowed type'];
    }


    protected static function typeName() : string
    {
        return '';
    }
}
