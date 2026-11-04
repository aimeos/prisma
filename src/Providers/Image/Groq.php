<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Groq as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Groq extends Base implements Describe
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $input = $this->responsesInput( $this->summary( $lang ), [$image] );

        return $this->responses( 'openai/v1/responses', 'qwen/qwen3.8-27b', $input, [] );
    }
}
