<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Groq extends Base
{
    use CallsTools;
    use OpenaiApi;

    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.groq.com' ) );
    }


    /**
     * Maps the tool choice to the values supported by Groq.
     *
     * Groq supports "auto" and "required" but not "none", which is omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return match( $this->toolChoice() ) {
            self::AUTO => 'auto',
            self::REQ => 'required',
            default => null,
        };
    }
}
