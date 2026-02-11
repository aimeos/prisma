<?php

namespace Aimeos\Prisma\Responses;

use Aimeos\Prisma\Concerns\Async;
use Aimeos\Prisma\Concerns\HasDescription;
use Aimeos\Prisma\Concerns\HasMeta;
use Aimeos\Prisma\Concerns\HasUsage;
use Aimeos\Prisma\Files\File;


/**
 * File based response.
 */
class FileResponse extends File
{
    use Async, HasDescription, HasMeta, HasUsage;


    /**
     * Returns the binary content, waiting if necessary.
     *
     * @return string|null Binary content
     */
    public function binary() : ?string
    {
        return parent::binary() ?? $this->wait();
    }


    protected function content() : ?string
    {
        return $this->binary;
    }

    protected function setContent( ?string $content ) : ?string
    {
        return $this->binary = $content;
    }
}
