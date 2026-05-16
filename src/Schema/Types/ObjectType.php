<?php

namespace Aimeos\Prisma\Schema\Types;


class ObjectType extends Type
{
    protected ?bool $additionalProperties = null;
    protected array $properties = [];


    public function __construct( array $properties = [] )
    {
        $this->properties = $properties;
    }


    public static function fromArray( array $def ) : self
    {
        $required = is_array( $def['required'] ?? null ) ? $def['required'] : [];
        $props = is_array( $def['properties'] ?? null ) ? $def['properties'] : [];
        $properties = [];

        foreach( $props as $name => $propDef )
        {
            if( !is_string( $name ) || !is_array( $propDef ) ) {
                continue;
            }

            $prop = Type::fromArray( $propDef );
            $prop->required = in_array( $name, $required ) ?: null;
            $properties[$name] = $prop;
        }

        $type = new self( $properties );
        $type->additionalProperties = is_bool( $def['additionalProperties'] ?? null ) ? $def['additionalProperties'] : null;
        $type->default = is_array( $def['default'] ?? null ) ? $def['default'] : null;
        return $type;
    }


    public function default( array $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    public function toArray() : array
    {
        $required = array_keys( array_filter(
            $this->properties,
            fn( Type $p ) => $p->required === true
        ) );

        return array_filter( parent::toArray() + [
            'properties' => array_map( fn( Type $p ) => $p->toArray(), $this->properties ) ?: null,
            'required' => $required ?: null,
            'additionalProperties' => $this->additionalProperties,
        ], fn( $v ) => $v !== null );
    }


    public function withoutAdditionalProperties() : static
    {
        $this->additionalProperties = false;
        return $this;
    }


    protected static function typeName() : string
    {
        return 'object';
    }
}
