<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Z extends Base
{
    use CallsTools;
    use OpenaiApi;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.z.ai' ) );
    }


    /**
     * Maps the tool choice to values known by Z.AI.
     *
     * The Chat Completion API currently exposes `auto` only in the supported values;
     * forcing another mode is omitted to avoid rejected requests.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return $this->toolChoice() === self::AUTO ? 'auto' : null;
    }
}
