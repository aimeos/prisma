<?php

namespace Aimeos\Prisma\Contracts\Audio;

use Aimeos\Prisma\Responses\FileResponse;


interface Synthesize
{
    /**
     * Generates sound effects from a prompt.
     *
     * @param string $prompt Prompt describing the sound effect
     * @param array<string, mixed> $options Common and provider specific options
     * @return FileResponse Audio file response
     */
    public function synthesize( string $prompt, array $options = [] ) : FileResponse;
}
