<?php

namespace Aimeos\Prisma\Files;


/**
 * Video file content.
 */
class Video extends File
{
    protected function mimePrefix() : string
    {
        return 'video/';
    }
}
