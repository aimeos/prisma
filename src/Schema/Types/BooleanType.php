<?php

namespace Aimeos\Prisma\Schema\Types;


class BooleanType extends Type
{
    public function default( bool $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    /**
     * Creates a boolean type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $type = new self();
        $type->default = is_bool( $def['default'] ?? null ) ? $def['default'] : null;

        return $type;
    }


    protected function check( mixed $data, array $defs, string $path ) : array
    {
        return is_bool( $data ) ? [] : [$this->label( $path ) . ' must be a boolean'];
    }


    protected static function typeName() : string
    {
        return 'boolean';
    }
}
