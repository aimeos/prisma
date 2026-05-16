<?php

namespace Aimeos\Prisma\Schema\Types;


class BooleanType extends Type
{
    public static function fromArray( array $def ) : self
    {
        $type = new self();
        $type->default = is_bool( $def['default'] ?? null ) ? $def['default'] : null;

        return $type;
    }


    public function default( bool $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    protected static function typeName() : string
    {
        return 'boolean';
    }
}
