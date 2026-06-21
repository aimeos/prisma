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
    /** @var array<int, string|int|null>|null */
    protected ?array $enum = null;
    protected ?bool $nullable = null;


    /**
     * Creates a type instance from a JSON Schema definition.
     *
     * @param array<string, mixed> $def JSON Schema type definition
     */
    public static function fromArray( array $def ) : self
    {
        if( isset( $def['$ref'] ) && is_string( $def['$ref'] ) ) {
            return RefType::fromArray( $def );
        }

        if( isset( $def['anyOf'] ) && is_array( $def['anyOf'] ) ) {
            return AnyOfType::fromArray( $def );
        }

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
            'null'    => NullType::fromArray( $def ),
            default   => StringType::fromArray( $def ),
        };

        $type->nullable = $nullable ?: null;
        $type->title = is_string( $def['title'] ?? null ) ? $def['title'] : null;
        $type->description = is_string( $def['description'] ?? null ) ? $def['description'] : null;
        $type->enum = isset( $def['enum'] ) && is_array( $def['enum'] )
            ? array_values( array_filter( $def['enum'], fn( $v ) => is_string( $v ) || is_int( $v ) ) )
            : null;

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


    /**
     * Sets the allowed enum values.
     *
     * @param array<int, string|int>|string $values Enum values or BackedEnum class
     */
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
        $this->nullable = $nullable ?: null;
        return $this;
    }


    public function required( bool $required = true ) : static
    {
        $this->required = $required ?: null;
        return $this;
    }


    public function title( string $value ) : static
    {
        $this->title = $value;
        return $this;
    }


    /**
     * Returns the type as a JSON Schema array.
     *
     * @return array<string, mixed> JSON Schema type definition
     */
    public function toArray() : array
    {
        $type = static::typeName();
        $enum = $this->enum;

        // A nullable enum must list null as an allowed value. Strict JSON Schema
        // validators (Anthropic, OpenAI) reject the schema otherwise because the
        // declared type permits null while the enum does not.
        if( $enum !== null && $this->nullable && !in_array( null, $enum, true ) ) {
            $enum[] = null;
        }

        return array_filter( [
            'type' => $this->nullable ? [$type, 'null'] : $type,
            'title' => $this->title,
            'description' => $this->description,
            'enum' => $enum,
            'default' => $this->default,
        ], fn( $v ) => $v !== null );
    }


    public function toString() : string
    {
        return json_encode( $this->toArray(), JSON_PRETTY_PRINT ) ?: '';
    }


    /**
     * Validates a value against this type, collecting error messages.
     *
     * The base handles the null/nullable and enum rules shared by every type and
     * delegates the type-specific checks to check(). Types that contain other types
     * (object, array, anyOf, $ref) recurse via their children's validate().
     *
     * @param mixed $data Value to validate
     * @param array<string, Type> $defs Reusable definitions for $ref resolution
     * @param string $path Property path for error messages
     * @return array<int, string> Validation error messages; empty when valid
     */
    public function validate( mixed $data, array $defs = [], string $path = '' ) : array
    {
        if( $data === null ) {
            return $this->nullable ? [] : [$this->label( $path ) . ' must not be null'];
        }

        $errors = $this->check( $data, $defs, $path );

        if( !$errors && $this->enum !== null )
        {
            // Strict match, with a numeric fallback so a JSON-decoded float (1.0) still
            // matches an integer enum member (1) for number-typed enums.
            $match = in_array( $data, $this->enum, true )
                || ( ( is_int( $data ) || is_float( $data ) ) && in_array( $data, $this->enum ) );

            if( !$match ) {
                $errors[] = $this->label( $path ) . ' is not one of the allowed values';
            }
        }

        return $errors;
    }


    /**
     * Validates numeric bounds shared by integer and number types.
     *
     * @param string $path Property path for error messages
     * @return array<int, string> Validation error messages
     */
    protected function bounds( int|float $data, int|float|null $min, int|float|null $max, int|float|null $multiple, string $path ) : array
    {
        $errors = [];

        if( $min !== null && $data < $min ) {
            $errors[] = sprintf( '%s must be at least %s', $this->label( $path ), $min );
        }

        if( $max !== null && $data > $max ) {
            $errors[] = sprintf( '%s must be at most %s', $this->label( $path ), $max );
        }

        if( $multiple )
        {
            // Compare the quotient against the nearest integer with a tolerance; fmod()
            // is not exact for fractional multiples (e.g. fmod(0.3, 0.1) != 0.0).
            $quotient = $data / $multiple;

            if( abs( $quotient - round( $quotient ) ) > 1e-9 ) {
                $errors[] = sprintf( '%s must be a multiple of %s', $this->label( $path ), $multiple );
            }
        }

        return $errors;
    }


    /**
     * Performs the type-specific validation; the base handles null and enum.
     *
     * @param mixed $data Value to validate (never null here)
     * @param array<string, Type> $defs Reusable definitions for $ref resolution
     * @param string $path Property path for error messages
     * @return array<int, string> Validation error messages
     */
    protected function check( mixed $data, array $defs, string $path ) : array
    {
        return [];
    }


    /**
     * Builds a dotted property path for nested error messages.
     */
    protected function join( string $path, string $key ) : string
    {
        return $path === '' ? $key : $path . '.' . $key;
    }


    /**
     * Returns the leading label for an error message at the given path.
     */
    protected function label( string $path ) : string
    {
        return $path === '' ? 'value' : sprintf( '"%s"', $path );
    }


    abstract protected static function typeName() : string;
}
