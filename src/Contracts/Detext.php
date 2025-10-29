<?php

namespace \Aimeos\Prisma\Contracts;


interface Detext
{
    /**
     * Remove all text from the image.
     *
     * @param Image $image Input image object
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function detext( Image $image, array $options = [] ) : FileResponse;
}