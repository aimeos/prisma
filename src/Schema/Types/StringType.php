<?php

namespace Aimeos\Prisma\Schema\Types;

use Aimeos\Prisma\Exceptions\PrismaException;


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
        // Reject an uncompilable regex at definition time instead of silently never
        // enforcing it; an empty string clears the constraint.
        if( $value !== '' && @preg_match( '~' . str_replace( '~', '\~', $value ) . '~u', '' ) === false ) {
            throw new PrismaException( sprintf( 'Invalid regular expression pattern: %s', $value ) );
        }

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


    protected function check( mixed $data, array $defs, string $path ) : array
    {
        if( !is_string( $data ) ) {
            return [$this->label( $path ) . ' must be a string'];
        }

        $errors = [];
        $length = mb_strlen( $data );
        $tooLong = $this->maxLength !== null && $length > $this->maxLength;

        if( $this->minLength !== null && $length < $this->minLength ) {
            $errors[] = sprintf( '%s must be at least %d characters', $this->label( $path ), $this->minLength );
        }

        if( $tooLong ) {
            $errors[] = sprintf( '%s must be at most %d characters', $this->label( $path ), $this->maxLength );
        }

        // Skipped for an over-long value, which bounds the regex backtracking cost to
        // maxLength. Matched per code point (the "u" flag) for consistency with the length
        // checks, falling back to byte mode for non-UTF-8 data so it isn't silently skipped;
        // the pattern is known to compile (see pattern()), so a 0 result is a real non-match.
        if( !$tooLong && $this->pattern !== null && $this->pattern !== '' )
        {
            $re = '~' . str_replace( '~', '\~', $this->pattern ) . '~';
            $match = @preg_match( $re . 'u', $data );

            if( $match === false ) {
                $match = @preg_match( $re, $data );
            }

            if( $match === 0 ) {
                $errors[] = $this->label( $path ) . ' does not match the required pattern';
            }
        }

        return $errors;
    }


    protected static function typeName() : string
    {
        return 'string';
    }
}
