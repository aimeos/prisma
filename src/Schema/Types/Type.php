<?php

namespace Aimeos\Prisma\Schema\Types;

use BackedEnum;
use InvalidArgumentException;


abstract class Type
{
    protected ?bool $required = null;
    protected ?string $title = null;
    protected ?string $description = null;
    protected mixed $default = null;
    protected ?array $enum = null;
    protected ?bool $nullable = null;


    public static function fromArray( array $def ) : self
    {
        $rawType = $def['type'] ?? 'string';
        $nullable = false;

        if( is_array( $rawType ) ) {
            $nullable = in_array( 'null', $rawType );
            $rawType = current( array_filter( $rawType, fn( $t ) => $t !== 'null' ) ) ?: 'string';
        }

        $type = match( is_string( $rawType ) ? $rawType : 'string' ) {
            'string'  => StringType::fromArray( $def ),
            'integer' => IntegerType::fromArray( $def ),
            'number'  => NumberType::fromArray( $def ),
            'boolean' => BooleanType::fromArray( $def ),
            'array'   => ArrayType::fromArray( $def ),
            'object'  => ObjectType::fromArray( $def ),
            default   => StringType::fromArray( $def ),
        };

        $type->nullable = $nullable ?: null;
        $type->title = is_string( $def['title'] ?? null ) ? $def['title'] : null;
        $type->description = is_string( $def['description'] ?? null ) ? $def['description'] : null;
        $type->enum = isset( $def['enum'] ) && is_array( $def['enum'] ) ? array_values( $def['enum'] ) : null;

        return $type;
    }


    public function __toString() : string
    {
        return $this->toString();
    }


    public function description( string $value ) : static
    {
        $this->description = $value;
        return $this;
    }


    public function enum( array|string $values ) : static
    {
        if( is_string( $values ) ) {
            if( !is_subclass_of( $values, BackedEnum::class ) ) {
                throw new InvalidArgumentException( 'The provided class must be a BackedEnum.' );
            }

            $values = array_column( $values::cases(), 'value' );
        }

        $this->enum = array_values( $values );

        return $this;
    }


    public function nullable( bool $nullable = true ) : static
    {
        if( $nullable ) {
            $this->nullable = true;
        }

        return $this;
    }


    public function required( bool $required = true ) : static
    {
        if( $required ) {
            $this->required = true;
        }

        return $this;
    }


    public function title( string $value ) : static
    {
        $this->title = $value;
        return $this;
    }


    public function toArray() : array
    {
        $type = static::typeName();

        return array_filter( [
            'type' => $this->nullable ? [$type, 'null'] : $type,
            'title' => $this->title,
            'description' => $this->description,
            'enum' => $this->enum,
            'default' => $this->default,
        ], fn( $v ) => $v !== null );
    }


    public function toString() : string
    {
        return json_encode( $this->toArray(), JSON_PRETTY_PRINT ) ?: '';
    }


    abstract protected static function typeName() : string;
}
