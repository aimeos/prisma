<?php

namespace Aimeos\Prisma\Contracts\Image;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;


interface Inpaint
{
    /**
     * Edit an image by inpainting an area defined by a mask according to a prompt.
     *
     * @param Image $image Input image object
     * @param string $prompt Prompt describing the changes
     * @param Image|null $mask Input mask image object
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function inpaint( Image $image, string $prompt, ?Image $mask = null, array $options = [] ) : FileResponse;
}