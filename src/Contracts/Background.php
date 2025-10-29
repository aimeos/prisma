<?php

namespace \Aimeos\Prisma\Contracts;


interface Background
{
    /**
     * Remove or replace image background.
     *
     * @param Image $image Input image object
     * @param ?string $promat Prompt describing the new background
     * @param array $options Provider specific options
     * @return Image Output image object
     */
    public function background( Image $image, ?string $prompt = null, array $options = [] ) : Image;
}