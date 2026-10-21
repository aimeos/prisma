<?php

namespace Aimeos\Prisma\Contracts\Audio;

use Aimeos\Prisma\Responses\FileResponse;


interface Compose
{
    /**
     * Generates music from a prompt.
     *
     * @param string $prompt Prompt describing the music
     * @param array<string, mixed> $options Common and provider specific options
     * @return FileResponse Audio file response
     */
    public function compose( string $prompt, array $options = [] ) : FileResponse;
}
