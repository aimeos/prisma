<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;


interface Translate
{
    /**
     * Translate the text content.
     *
     * @param array<string> $texts Input texts to be translated
     * @param string $to ISO language code to translate the text into
     * @param string|null $from ISO language code of the input text (optional)
     * @param string|null $context Context for the translation (optional)
     * @param array<string, mixed> $options Provider specific options (optional)
     * @return TextResponse Response text
     */
    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse;
}
