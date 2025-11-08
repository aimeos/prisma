<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;


/**
 * Text based response.
 */
class TextResponse
{
    use HasMeta, HasUsage;


    private ?string $text = null;


    final private function __construct()
    {
    }


    /**
     * Create a text response instance.
     *
     * @param string $text|null Text content
     * @return self TextResponse instance
     */
    public static function fromText( ?string $text ) : self
    {
        $instance = new self;
        $instance->text = $text;

        return $instance;
    }


    /**
     * Get the text content.
     *
     * @return string|null Text content
     */
    public function text() : ?string
    {
        return $this->text;
    }
}
