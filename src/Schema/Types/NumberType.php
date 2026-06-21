<?php

namespace Aimeos\Prisma\Schema\Types;


class NumberType extends Type
{
    protected int|float|null $minimum = null;
    protected int|float|null $maximum = null;
    protected int|float|null $multipleOf = null;


    /**
     * Creates a number type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $type = new self();
        $type->default = is_int( $def['default'] ?? null ) || is_float( $def['default'] ?? null ) ? $def['default'] : null;
        $type->minimum = is_int( $def['minimum'] ?? null ) || is_float( $def['minimum'] ?? null ) ? $def['minimum'] : null;
        $type->maximum = is_int( $def['maximum'] ?? null ) || is_float( $def['maximum'] ?? null ) ? $def['maximum'] : null;
        $type->multipleOf = is_int( $def['multipleOf'] ?? null ) || is_float( $def['multipleOf'] ?? null ) ? $def['multipleOf'] : null;

        return $type;
    }


    public function default( int|float $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    public function max( int|float $value ) : static
    {
        $this->maximum = $value;
        return $this;
    }


    public function min( int|float $value ) : static
    {
        $this->minimum = $value;
        return $this;
    }


    public function multipleOf( int|float $value ) : static
    {
        $this->multipleOf = $value;
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
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'multipleOf' => $this->multipleOf,
        ], fn( $v ) => $v !== null );
    }


    protected function check( mixed $data, array $defs, string $path ) : array
    {
        if( !is_int( $data ) && !( is_float( $data ) && is_finite( $data ) ) ) {
            return [$this->label( $path ) . ' must be a number'];
        }

        return $this->bounds( $data, $this->minimum, $this->maximum, $this->multipleOf, $path );
    }


    protected static function typeName() : string
    {
        return 'number';
    }
}
