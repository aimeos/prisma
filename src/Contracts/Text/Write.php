<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;


interface Write
{
    /**
     * Generate text from the given prompt.
     *
     * @param string $prompt Input prompt for text generation
     * @param array<int, \Aimeos\Prisma\Files\File> $files Files for multimodal input (images, audio, documents)
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Response text
     */
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse;
}
