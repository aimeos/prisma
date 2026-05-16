<?php

namespace Aimeos\Prisma\Schema\Types;


class IntegerType extends Type
{
    protected ?int $minimum = null;
    protected ?int $maximum = null;
    protected ?int $multipleOf = null;


    public static function fromArray( array $def ) : self
    {
        $type = new self();
        $type->minimum = is_int( $def['minimum'] ?? null ) ? $def['minimum'] : null;
        $type->maximum = is_int( $def['maximum'] ?? null ) ? $def['maximum'] : null;
        $type->multipleOf = is_int( $def['multipleOf'] ?? null ) ? $def['multipleOf'] : null;
        $type->default = is_int( $def['default'] ?? null ) ? $def['default'] : null;

        return $type;
    }


    public function default( int $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    public function max( int $value ) : static
    {
        $this->maximum = $value;
        return $this;
    }


    public function min( int $value ) : static
    {
        $this->minimum = $value;
        return $this;
    }


    public function multipleOf( int $value ) : static
    {
        $this->multipleOf = $value;
        return $this;
    }


    public function toArray() : array
    {
        return array_filter( parent::toArray() + [
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'multipleOf' => $this->multipleOf,
        ], fn( $v ) => $v !== null );
    }


    protected static function typeName() : string
    {
        return 'integer';
    }
}
