<?php

namespace Aimeos\Prisma\Values;

use Aimeos\Prisma\Exceptions\BadRequestException;


/**
 * Structured output mode.
 *
 * "structured" (the default) uses the provider's native strict json_schema support,
 * "json" embeds the schema in the prompt and parses the JSON from the response text.
 */
enum Mode
{
    case Json;
    case Structured;


    /**
     * Resolves the requested structured output mode.
     *
     * @param string|null $mode Requested mode ("json"/"structured") or null for the default (structured)
     * @throws \Aimeos\Prisma\Exceptions\BadRequestException If $mode is not null, "json" or "structured"
     */
    public static function from( ?string $mode ) : self
    {
        return match( $mode ) {
            'json' => self::Json,
            'structured', null => self::Structured,
            default => throw new BadRequestException( sprintf( 'Invalid structured output mode "%s", use "json" or "structured"', $mode ) ),
        };
    }


    /**
     * Tells whether prompt-embedded JSON mode should be used instead of native strict mode.
     */
    public function isJson() : bool
    {
        return $this === self::Json;
    }
}
