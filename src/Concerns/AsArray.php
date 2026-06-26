<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Array-like read access to a string-keyed data map.
 *
 * Lets a value object stand in for the plain array it replaced: subscript access, isset(),
 * null coalescing, iteration, count() and JSON serialization all keep working unchanged.
 */
trait AsArray
{
    /** @var array<string, mixed> */
    protected array $data = [];


    /**
     * Returns the complete data map.
     *
     * @return array<string, mixed> Data map
     */
    public function all() : array
    {
        return $this->data;
    }


    /**
     * Returns the number of entries.
     *
     * @return int Number of entries
     */
    public function count() : int
    {
        return count( $this->data );
    }


    /**
     * Returns an iterator over the data map.
     *
     * @return \Traversable<string, mixed> Iterator
     */
    public function getIterator() : \Traversable
    {
        return new \ArrayIterator( $this->data );
    }


    /**
     * Returns the data map for JSON serialization.
     *
     * @return array<string, mixed> Data map
     */
    public function jsonSerialize() : array
    {
        return $this->data;
    }


    /**
     * Checks if the given key is set and not NULL.
     *
     * @param mixed $key Map key
     * @return bool TRUE if set, FALSE if not
     */
    public function offsetExists( mixed $key ) : bool
    {
        return is_string( $key ) && isset( $this->data[$key] );
    }


    /**
     * Returns the value for the given key or NULL if not set.
     *
     * @param mixed $key Map key
     * @return mixed Stored value or NULL
     */
    public function offsetGet( mixed $key ) : mixed
    {
        return is_string( $key ) ? ( $this->data[$key] ?? null ) : null;
    }


    /**
     * Sets the value for the given key.
     *
     * @param mixed $key Map key
     * @param mixed $value Value to store
     */
    public function offsetSet( mixed $key, mixed $value ) : void
    {
        if( is_string( $key ) ) {
            $this->data[$key] = $value;
        }
    }


    /**
     * Removes the value for the given key.
     *
     * @param mixed $key Map key
     */
    public function offsetUnset( mixed $key ) : void
    {
        if( is_string( $key ) ) {
            unset( $this->data[$key] );
        }
    }
}
