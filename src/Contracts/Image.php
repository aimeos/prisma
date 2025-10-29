<?php

namespace \Aimeos\Prisma\Contracts;


interface Image
{
    /**
     * Generate an image from the prompt.
     *
     * @param string $prompt Prompt describing the image
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function image( string $prompt, array $options = [] ) : FileResponse;
}