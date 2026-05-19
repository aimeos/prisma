<?php

namespace Aimeos\Prisma\Files;


/**
 * Image file content.
 */
class Image extends File
{
    protected function mimePrefix() : string
    {
        return 'image/';
    }
}
