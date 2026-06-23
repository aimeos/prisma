<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


interface Structure
{
    /**
     * Generate structured output from the given prompt and schema.
     *
     * WARNING: TextResponse::structured() returns the model output parsed as-is. Its
     * shape is NOT validated against the schema (native strict mode is provider-enforced,
     * JSON mode is not) -- check it with Schema::validate(). Its values are model-generated
     * text in every mode, so escape or parameterize them at any sink (SQL, shell, paths,
     * markup); schema conformance is not value safety.
     *
     * @param string $prompt Input prompt for structured text generation
     * @param Schema $schema Schema definition for the structured output
     * @param array<int, \Aimeos\Prisma\Files\File> $files Files for multimodal input (images, audio, documents)
     * @param array<string, mixed> $options Provider options; "mode" => "json"|"structured" selects JSON vs native strict output
     * @return TextResponse Response text with structured data
     */
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse;
}
