<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\FileResponse;


interface Repaint
{
    /**
     * Repaint a video according to the prompt.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the changes
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file
     */
    public function repaint( Video $video, string $prompt, array $options = [] ) : FileResponse;
}
