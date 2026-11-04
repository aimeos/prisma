<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Gemini extends Base implements Describe
{
    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->understand( $video, $this->summary( $lang ), 'gemini-flash-latest' );
    }
}
