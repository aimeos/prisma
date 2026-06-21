<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\Async;
use Aimeos\Prisma\Concerns\HasCitations;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasRateLimit;
use Aimeos\Prisma\Concerns\HasReason;
use Aimeos\Prisma\Concerns\HasToolSteps;
use Aimeos\Prisma\Concerns\HasUsage;


/**
 * Text based response.
 *
 * @implements \IteratorAggregate<int|string, string|null>
 */
class TextResponse implements \IteratorAggregate
{
    use Async, HasCitations, HasMeta, HasRateLimit, HasReason, HasToolSteps, HasUsage;


    /** @var array<string|int, mixed> */
    private array $structured = [];

    /** @var array<string|int, string|null> */
    private array $list = [];

    final private function __construct()
    {
    }


    public static function fromText( ?string $text ) : self
    {
        $instance = new self;
        $instance->list[] = $text;

        return $instance;
    }


    /**
     * Creates a response from multiple text values.
     *
     * @param array<string|int, string|null> $texts Response texts
     */
    public static function fromTexts( array $texts ) : self
    {
        $instance = new self;
        $instance->list = $texts;

        return $instance;
    }


    public function add( ?string $text, int|string|null $key = null ) : self
    {
        if( $key !== null ) {
            $this->list[$key] = $text;
        } else {
            $this->list[] = $text;
        }

        return $this;
    }


    public function empty() : bool
    {
        return empty( $this->list );
    }


    public function first() : ?string
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        $text = reset( $this->list );
        return $text === false || $text === '' ? null : $text;
    }


    public function getIterator(): \Traversable
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return new \ArrayIterator( $this->list );
    }


    /**
     * Returns the structured output data.
     *
     * @return array<string|int, mixed> Structured data
     */
    public function structured() : array
    {
        return $this->structured;
    }


    public function text() : ?string
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        $text = current( $this->list );
        return $text === false || $text === '' ? null : $text;
    }


    /**
     * Returns all response texts.
     *
     * @return array<string|int, string|null> Response texts
     */
    public function texts() : array
    {
        if( empty( $this->list ) ) {
            $this->wait();
        }

        return $this->list;
    }


    /**
     * Sets the structured output data.
     *
     * @param array<string|int, mixed> $structured Structured data
     */
    public function withStructured( array $structured ) : static
    {
        $this->structured = $structured;
        return $this;
    }
}
