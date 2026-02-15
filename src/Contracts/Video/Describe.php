<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\TextResponse;


interface Describe
{
    /**
     * Describe the content of a video.
     *
     * @param Video $video Input video object
     * @param string|null $lang ISO language code the description should be generated in
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Response text
     */
    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse;
}