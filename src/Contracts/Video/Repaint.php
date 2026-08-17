<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\FileResponse;


interface Repaint
{
    /**
     * Repaint a video according to the prompt.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the changes
     * @param array{references?: array<int, Audio|Image|Video>} $media Reference media by semantic role
     * @param array<string, mixed> $options Common and provider-specific options
     * @return FileResponse Response file
     */
    public function repaint( Video $video, string $prompt, array $media = [], array $options = [] ) : FileResponse;
}
