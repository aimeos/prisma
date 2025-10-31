<?php

namespace Aimeos\Prisma\Contracts\Image;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;


interface Upscale
{
    /**
     * Scale up the image.
     *
     * @param Image $image Input image object
     * @param int $width Width of the upscaled image in pixels
     * @param int $height Height of the upscaled image in pixels
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function upscale( Image $image, int $width, int $height, array $options = [] ) : FileResponse;
}