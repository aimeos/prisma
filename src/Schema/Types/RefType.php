<?php

namespace Aimeos\Prisma\Schema\Types;


class RefType extends Type
{
    protected string $ref = '';


    /**
     * Creates a new reference type pointing to the given JSON pointer.
     *
     * Plain names are resolved relative to the "$defs" section, e.g. "Address"
     * becomes "#/$defs/Address". Full JSON pointers starting with "#" are kept.
     *
     * @param string $ref Definition name or JSON pointer
     */
    public function __construct( string $ref = '' )
    {
        $this->ref = $ref !== '' && $ref[0] === '#' ? $ref : '#/$defs/' . $ref;
    }


    /**
     * Creates a reference type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $type = new self( is_string( $def['$ref'] ?? null ) ? $def['$ref'] : '' );
        $type->title = is_string( $def['title'] ?? null ) ? $def['title'] : null;
        $type->description = is_string( $def['description'] ?? null ) ? $def['description'] : null;

        return $type;
    }


    /**
     * Returns the type as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema type definition
     */
    public function toArray() : array
    {
        return array_filter( [
            '$ref' => $this->ref,
            'title' => $this->title,
            'description' => $this->description,
        ], fn( $v ) => $v !== null );
    }


    protected static function typeName() : string
    {
        return '';
    }
}
