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
     * @param float $top Requested fraction of the source height to add at the top; negative values are treated as 0
     * @param float $right Requested fraction of the source width to add at the right; negative values are treated as 0
     * @param float $bottom Requested fraction of the source height to add at the bottom; negative values are treated as 0
     * @param float $left Requested fraction of the source width to add at the left; negative values are treated as 0
     * @param array<string, mixed> $options Provider specific options
     * @return FileResponse Response file
     * @throws BadRequestException If an expansion is not finite or all expansions are zero
     */
    public function uncrop( Video $video, string $prompt, float $top, float $right, float $bottom, float $left, array $options = [] ) : FileResponse;
}
