<?php

namespace Aimeos\Prisma\Files;


/**
 * Audio file content.
 */
class Audio extends File
{
    protected function mimePrefix() : string
    {
        return 'audio/';
    }
}
