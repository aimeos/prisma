<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\Async;
use Aimeos\Prisma\Concerns\HasCitations;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasRateLimit;
use Aimeos\Prisma\Concerns\HasReason;
use Aimeos\Prisma\Concerns\HasToolSteps;
use Aimeos\Prisma\Concerns\HasUsage;
use Aimeos\Prisma\Concerns\Stream;


/**
 * Text based response.
 *
 * @implements \IteratorAggregate<int|string, string|null>
 */
class TextResponse implements \IteratorAggregate
{
    use Async, HasCitations, HasMeta, HasRateLimit, HasReason, HasToolSteps, HasUsage, Stream;


    /** @var array<string|int, mixed> */
    private array $structured = [];

    /** @var array<string|int, string|null> */
    private array $list = [];

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
     * Adds multiple text values, preserving their keys.
     *
     * @param array<string|int, string|null> $texts Response texts
     */
    public function addAll( array $texts ) : self
    {
        foreach( $texts as $key => $text ) {
            $this->add( $text, $key );
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
            $this->ensure();
        }

        $text = reset( $this->list );
        return $text === false || $text === '' ? null : $text;
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


    public function getIterator(): \Traversable
    {
        if( empty( $this->list ) ) {
            $this->ensure();
        }

        return new \ArrayIterator( $this->list );
    }


    /**
     * Returns all response texts concatenated into a single string.
     *
     * text() returns only the first entry; this combines every collected text, which
     * is useful when a model emits its answer across multiple steps (e.g. text before
     * and after tool calls).
     *
     * @return string Combined response text
     */
    public function output() : string
    {
        return implode( '', array_map( fn( $text ) => (string) $text, $this->texts() ) );
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
            $this->ensure();
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
            $this->ensure();
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


    /**
     * Populates the response from whichever resolution mode backs it.
     *
     * Drains the stream (chat) and polls the async job (transcription); each is a no-op unless
     * its mode is active, so the single call covers stream-backed, poll-backed and eager responses.
     */
    private function ensure() : void
    {
        $this->resolve();
        $this->wait();
    }


    final private function __construct()
    {
    }
}
