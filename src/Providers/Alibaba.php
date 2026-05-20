<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Alibaba extends Base
{
    use CallsTools;
    use OpenaiApi;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->cfg( $config, 'api_key' ) );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://dashscope-intl.aliyuncs.com' ) );
    }
}
