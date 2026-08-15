<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\FileResponse;


interface Upscale
{
    /**
     * Scale up the video.
     *
     * @param Video $video Input video object
     * @param int $factor Upscaling factor between 2 and the maximum value supported by the provider
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file
     */
    public function upscale( Video $video, int $factor, array $options = [] ) : FileResponse;
}
