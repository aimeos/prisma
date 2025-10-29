<?php

namespace \Aimeos\Prisma\Contracts;


interface Background
{
    /**
     * Remove or replace image background.
     *
     * @param Image $image Input image object
     * @param ?string $prompt Prompt describing the new background
     * @param array $options Provider specific options
     * @return FileResponse Response file
     */
    public function background( Image $image, ?string $prompt = null, array $options = [] ) : FileResponse;
}