<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;


class TextResponse
{
    use HasMeta, HasUsage;


    private ?string $text = null;


    private function __construct()
    {
    }


    public static function fromText( string $text ) : self
    {
        $instance = new static;
        $instance->text = $text;

        return $instance;
    }


    public function text() : ?string
    {
        return $this->text;
    }
}
