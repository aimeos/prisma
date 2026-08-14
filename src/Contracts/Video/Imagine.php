<?php

namespace Aimeos\Prisma\Contracts\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Responses\FileResponse;


interface Imagine
{
    /**
     * Generate a video from a prompt and optional media.
     *
     * Unsupported media roles and combinations are ignored by each provider.
     *
     * @param string $prompt Prompt describing the video
     * @param array{start?: Image, end?: Image, references?: array<int, Audio|Image|Video>} $media Input media by semantic role
     * @param array<string, mixed> $options Common and provider-specific options
     * @return FileResponse Generated video response
     */
    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse;
}
