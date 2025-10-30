<?php

namespace Aimeos\Prisma\Contracts;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;


interface Studio
{
    /**
     * Create studio photo from the object in the foreground of the image.
     *
     * @param Image $image Input image object
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function studio( Image $image, array $options = [] ) : FileResponse;
}