<?php

namespace Aimeos\Prisma\Contracts\Audio;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Responses\FileResponse;


interface Denoise
{
    /**
     * Remove noise from an audio file.
     *
     * @param Audio $audio Input audio object
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file object
     */
    public function denoise( Audio $audio, array $options = [] ) : FileResponse;
}
