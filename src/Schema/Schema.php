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


    public static function string() : Types\StringType
    {
        return new Types\StringType;
    }


    public function __toString() : string
    {
        return $this->toString();
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
}
