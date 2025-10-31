<?php

namespace Aimeos\Prisma\Contracts\Image;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;


interface Clear
{
    /**
     * Remove the image background.
     *
     * @param Image $image Input image object
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function clear( Image $image, array $options = [] ) : FileResponse;
}