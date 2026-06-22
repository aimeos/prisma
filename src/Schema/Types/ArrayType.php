<?php

namespace Aimeos\Prisma\Schema\Types;


class ArrayType extends Type
{
    protected ?Type $items = null;
    protected ?int $minItems = null;
    protected ?int $maxItems = null;
    protected ?bool $uniqueItems = null;


    /**
     * Sets the default value.
     *
     * @param array<int, mixed> $value Default array value
     */
    public function default( array $value ) : static
    {
        $this->default = $value;
        return $this;
    }


    /**
     * Creates an array type from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        $items = is_array( $def['items'] ?? null ) ? $def['items'] : null;

        $type = new self();
        /** @var array<string, mixed> $items */
        $type->items = $items !== null ? Type::fromArray( $items ) : null;
        $type->default = is_array( $def['default'] ?? null ) ? $def['default'] : null;
        $type->minItems = is_int( $def['minItems'] ?? null ) ? $def['minItems'] : null;
        $type->maxItems = is_int( $def['maxItems'] ?? null ) ? $def['maxItems'] : null;
        $type->uniqueItems = is_bool( $def['uniqueItems'] ?? null ) ? $def['uniqueItems'] : null;

        return $type;
    }


    public function items( Type $type ) : static
    {
        $this->items = $type;
        return $this;
    }


    public function max( int $value ) : static
    {
        $this->maxItems = $value;
        return $this;
    }


    public function min( int $value ) : static
    {
        $this->minItems = $value;
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
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'uniqueItems' => $this->uniqueItems,
            'items' => $this->items?->toArray(),
        ], fn( $v ) => $v !== null );
    }


    public function unique( bool $unique = true ) : static
    {
        if( $unique ) {
            $this->uniqueItems = true;
        }

        return $this;
    }


    protected function check( mixed $data, array $defs, string $path ) : array
    {
        if( !is_array( $data ) || ( $data !== [] && !array_is_list( $data ) ) ) {
            return [$this->label( $path ) . ' must be an array'];
        }

        $errors = [];
        $count = count( $data );

        if( $this->minItems !== null && $count < $this->minItems ) {
            $errors[] = sprintf( '%s must have at least %d items', $this->label( $path ), $this->minItems );
        }

        if( $this->maxItems !== null && $count > $this->maxItems ) {
            $errors[] = sprintf( '%s must have at most %d items', $this->label( $path ), $this->maxItems );
        }

        if( $this->uniqueItems )
        {
            $seen = [];

            foreach( $data as $item )
            {
                // Strict, type-sensitive equality per JSON Schema: numbers compare by value
                // (1 and 1.0 collide) but distinct types stay distinct (1 and "1" differ).
                $key = match( true ) {
                    is_int( $item ), is_float( $item ) => 'n:' . $item,
                    is_string( $item ) => 's:' . $item,
                    default => 'j:' . json_encode( $item ),
                };

                if( isset( $seen[$key] ) ) {
                    $errors[] = $this->label( $path ) . ' must not contain duplicate items';
                    break;
                }

                $seen[$key] = true;
            }
        }

        if( $this->items !== null )
        {
            foreach( $data as $i => $item ) {
                $errors = array_merge( $errors, $this->items->validate( $item, $defs, $this->join( $path, (string) $i ) ) );
            }
        }

        return $errors;
    }


    protected static function typeName() : string
    {
        return 'array';
    }
}
