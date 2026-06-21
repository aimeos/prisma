<?php

namespace Aimeos\Prisma\Schema;


class Schema
{
    private Types\ObjectType $type;
    private bool $strict = false;
    private string $name;


    /**
     * Creates a new schema instance.
     *
     * @param array<string, \Aimeos\Prisma\Schema\Types\Type> $properties Schema properties
     */
    public function __construct( string $name, array $properties = [] )
    {
        $this->name = $name;
        $this->type = new Types\ObjectType( $properties );
    }


    /**
     * Creates an anyOf type allowing any of the given types (JSON Schema "anyOf").
     *
     * @param array<int, \Aimeos\Prisma\Schema\Types\Type> $types Allowed types
     */
    public static function anyOf( array $types = [] ) : Types\AnyOfType
    {
        return new Types\AnyOfType( $types );
    }


    public static function array() : Types\ArrayType
    {
        return new Types\ArrayType;
    }


    public static function boolean() : Types\BooleanType
    {
        return new Types\BooleanType;
    }


    /**
     * Creates a named schema with the given properties.
     *
     * @param array<string, \Aimeos\Prisma\Schema\Types\Type> $properties Schema properties
     */
    public static function for( string $name, array $properties = [] ) : self
    {
        return new self( $name, $properties );
    }


    /**
     * Creates a schema from a JSON Schema array.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     */
    public static function fromArray( string $name, array $schema ) : self
    {
        $instance = new self( $name, [] );
        $instance->type = Types\ObjectType::fromArray( $schema );

        return $instance;
    }


    public static function integer() : Types\IntegerType
    {
        return new Types\IntegerType;
    }


    public static function number() : Types\NumberType
    {
        return new Types\NumberType;
    }


    /**
     * Creates an object type with the given properties.
     *
     * @param array<string, \Aimeos\Prisma\Schema\Types\Type> $properties Object properties
     */
    public static function object( array $properties = [] ) : Types\ObjectType
    {
        return new Types\ObjectType( $properties );
    }


    /**
     * Creates a reference to a reusable definition (JSON Schema "$ref").
     *
     * Plain names resolve to the "$defs" section, e.g. "Address" becomes
     * "#/$defs/Address". Full JSON pointers starting with "#" are kept as-is.
     */
    public static function ref( string $ref ) : Types\RefType
    {
        return new Types\RefType( $ref );
    }


    public static function string() : Types\StringType
    {
        return new Types\StringType;
    }


    public function __toString() : string
    {
        return $this->toString();
    }


    /**
     * Registers a reusable definition referenced via "$ref".
     */
    public function def( string $name, Types\Type $type ) : static
    {
        $this->type->def( $name, $type );
        return $this;
    }


    public function isStrict() : bool
    {
        return $this->strict;
    }


    public function name() : string
    {
        return $this->name;
    }


    public function strict( bool $strict = true ) : static
    {
        $this->strict = $strict;
        return $this;
    }


    /**
     * Returns a filtered schema array keeping only the allowed keys.
     *
     * @param array<int, string> $keys Allowed JSON Schema keys
     * @return array<string, mixed> Filtered JSON Schema definition
     */
    public function filter( array $keys ) : array
    {
        $flip = array_flip( $keys );

        return self::map( $this->toArray(), fn( array $node ) => array_intersect_key( $node, $flip ) );
    }


    /**
     * Returns the schema as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema definition
     */
    public function toArray() : array
    {
        return $this->type->toArray();
    }


    public function toString() : string
    {
        return json_encode( $this->toArray() ) ?: '';
    }


    public function type() : Types\ObjectType
    {
        return $this->type;
    }


    /**
     * Validates a value against this schema.
     *
     * Intended to check untrusted, model-supplied input (e.g. the decoded arguments of a
     * tool call) before it reaches a handler. Each type validates its own value, so only
     * the constraints this builder can express are evaluated.
     *
     * @param mixed $data Value to validate
     * @return array<int, string> Validation error messages; an empty array means the value is valid
     */
    public function validate( mixed $data ) : array
    {
        return $this->type->validate( $data );
    }


    /**
     * Recursively applies a transform to a JSON Schema array and its sub-schemas.
     *
     * The callback transforms one (sub-)schema node; map() then recurses into the node's
     * properties, items, anyOf and $defs. This is the single schema-tree traversal shared
     * by the provider jsonSchema() hooks and filter() so the recursion lives in one place.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @param callable(array<string, mixed>): array<string, mixed> $node Per-node transform
     * @return array<string, mixed> Transformed schema
     */
    public static function map( array $schema, callable $node ) : array
    {
        $schema = $node( $schema );

        foreach( ['properties', 'anyOf', '$defs'] as $key )
        {
            if( isset( $schema[$key] ) && is_array( $schema[$key] ) ) {
                $schema[$key] = array_map( fn( $sub ) => is_array( $sub ) ? self::map( $sub, $node ) : $sub, $schema[$key] );
            }
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = self::map( $schema['items'], $node );
        }

        return $schema;
    }
}
