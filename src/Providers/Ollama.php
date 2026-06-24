<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;


class Ollama extends Base
{
    use CallsTools;
    use OpenaiApi;

    public function __construct( array $config )
    {
        if( $key = $this->config( $config, 'api_key' ) ) {
            $this->header( 'Authorization', 'Bearer ' . $key );
        }

        $this->baseUrl( $this->config( $config, 'url', 'http://localhost:11434' ) );
    }
}
