<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\FileResponse;


interface Uncrop
{
    /**
     * Extend/outpaint the video frame.
     *
     * @param Video $video Input video object
     * @param string $prompt Prompt describing the extended scene
     * @param float $top Fraction of the source height to add at the top
     * @param float $right Fraction of the source width to add at the right
     * @param float $bottom Fraction of the source height to add at the bottom
     * @param float $left Fraction of the source width to add at the left
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file
     * @throws BadRequestException If an expansion is not finite or all expansions are zero
     */
    public function uncrop( Video $video, string $prompt, float $top, float $right, float $bottom, float $left, array $options = [] ) : FileResponse;
}
