<?php

namespace Aimeos\Prisma\Contracts\Audio;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Responses\FileResponse;


interface Demix
{
    /**
     * Separate an audio file into its individual tracks.
     *
     * @param Audio $audio Input audio object
     * @param int $stems Number of stems to separate into (e.g. 2 for vocals and accompaniment)
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file object
     */
    public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse;
}
