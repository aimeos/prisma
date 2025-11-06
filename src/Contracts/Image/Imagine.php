<?php

namespace Aimeos\Prisma\Contracts\Image;

use Aimeos\Prisma\Responses\FileResponse;


interface Imagine
{
    /**
     * Generate an image from the prompt.
     *
     * @param string $prompt Prompt describing the image
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function imagine( string $prompt, array $options = [] ) : FileResponse;
}