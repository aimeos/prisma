<?php

namespace Aimeos\Prisma\Schema\Types;


class StringType extends Type
{
    protected ?int $minLength = null;
    protected ?int $maxLength = null;
    protected ?string $pattern = null;
    protected ?string $format = null;


    /**
     * Creates a string type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $type = new self();
        $type->default = is_string( $def['default'] ?? null ) ? $def['default'] : null;
        $type->minLength = is_int( $def['minLength'] ?? null ) ? $def['minLength'] : null;
        $type->maxLength = is_int( $def['maxLength'] ?? null ) ? $def['maxLength'] : null;
        $type->pattern = is_string( $def['pattern'] ?? null ) ? $def['pattern'] : null;
        $type->format = is_string( $def['format'] ?? null ) ? $def['format'] : null;

        return $type;
    }


    public function default( string $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    public function format( string $value ) : static
    {
        $this->format = $value;
        return $this;
    }


    public function max( int $value ) : static
    {
        $this->maxLength = $value;
        return $this;
    }


    public function min( int $value ) : static
    {
        $this->minLength = $value;
        return $this;
    }


    public function pattern( string $value ) : static
    {
        $this->pattern = $value;
        return $this;
    }


    /**
     * Returns the type as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema type definition
     */
    public function toArray() : array
    {
        return array_filter( parent::toArray() + [
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'format' => $this->format,
        ], fn( $v ) => $v !== null );
    }


    protected static function typeName() : string
    {
        return 'string';
    }
}
