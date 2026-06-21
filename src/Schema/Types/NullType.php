<?php

namespace Aimeos\Prisma\Schema\Types;


class NullType extends Type
{
    /**
     * Creates a null type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        return new self();
    }


    /**
     * Validates that the value is null.
     *
     * @param array<string, Type> $defs Reusable definitions for $ref resolution
     * @return array<int, string> Validation error messages
     */
    public function validate( mixed $data, array $defs = [], string $path = '' ) : array
    {
        return $data === null ? [] : [$this->label( $path ) . ' must be null'];
    }


    protected static function typeName() : string
    {
        return 'null';
    }
}
