<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


interface Structured
{
    /**
     * Generate structured output from the given prompt and schema.
     *
     * @param string $prompt Input prompt for structured text generation
     * @param Schema $schema Schema definition for the structured output
     * @param array<int, \Aimeos\Prisma\Files\File> $files Files for multimodal input (images, audio, documents)
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Response text with structured data
     */
    public function structured( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse;
}
