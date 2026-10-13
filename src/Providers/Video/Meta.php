<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Meta as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Meta extends Base implements Describe
{
    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';
        $options = $this->allowed( $options, ['temperature', 'top_p', 'seed', 'safety_identifier', 'service_tier'] );

        return $this->completions(
            'v1/chat/completions', 'muse-spark-1.3',
            $this->messages( $this->content( $prompt, [$video] ) ),
            $options
        );
    }
}
