<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\Async;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;


/**
 * Text based response.
 *
 * @implements \IteratorAggregate<int|string, string|null>
 */
class TextResponse implements \IteratorAggregate
{
    use Async, HasMeta, HasUsage;


    /** @var array<string|int, mixed> */
    private array $structured = [];

    /** @var array<string|int, string|null> */
    private array $list = [];


    final private function __construct()
    {
    }


    /**
     * Create a text response instance.
     *
     * @param string|null $text Text content
     * @return self TextResponse instance
     */
    public static function fromText( ?string $text ) : self
    {
        $instance = new self;
        $instance->list[] = $text;

        return $instance;
    }


    /**
     * Create a text response instance.
     *
     * @param array<int|string, string> $texts List of texts
     * @return self TextResponse instance
     */
    public static function fromTexts( array $texts ) : self
    {
        $instance = new self;
        $instance->list = $texts;

        return $instance;
    }


    /**
     * Add a text to the list of texts if several are available.
     *
     * @param string|null $text Text content to add
     * @param int|string|null $key Optional key to associate with the text
     * @return self TextResponse instance for chaining
     */
    public function add( ?string $text, int|string|null $key = null ) : self
    {
        if( $key !== null ) {
            $this->list[$key] = $text;
        } else {
            $this->list[] = $text;
        }

        return $this;
    }


    /**
     * Checks if there are any results.
     *
     * @return bool True if there are no results, false otherwise
     */
    public function empty() : bool
    {
        return empty( $this->list );
    }


    /**
     * Returns the first text in the list if several are available.
     *
     * @return string|null First text or null if no texts are available
     */
    public function first() : ?string
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return reset( $this->list ) ?: null;
    }


    /**
     * Allows iterating over the list of available texts.
     *
     * @return \ArrayIterator<int|string, string|null> Traversable list of texts
     */
    public function getIterator(): \Traversable
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return new \ArrayIterator( $this->list );
    }


    /**
     * Get the structured data.
     *
     * @return array<string|int, mixed> Structured data
     */
    public function structured() : array
    {
        return $this->structured;
    }


    /**
     * Get the text content.
     *
     * @return string|null Text content
     */
    public function text() : ?string
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return current( $this->list ) ?: null;
    }


    /**
     * Get all available texts.
     *
     * @return array<int|string, string|null> List of texts
     */
    public function texts() : array
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return $this->list;
    }


    /**
     * Sets the structured data.
     *
     * @param array<string|int, mixed> $structured Structured data
     * @return static TextResponse instance
     */
    public function withStructured( array $structured ) : static
    {
        $this->structured = $structured;
        return $this;
    }
}
