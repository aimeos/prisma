<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\HasImageResponse;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Xai as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Xai extends Base implements Imagine
{
    use HasImageResponse;


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $request = [
            'model' => $this->modelName( 'grok-imagine-image-quality' ),
            'prompt' => $prompt,
        ] + $this->allowed( $options, ['n', 'response_format', 'user'] );

        $response = $this->client()->post( 'v1/images/generations', ['json' => $request] );

        return $this->toFileResponse( $response );
    }
}
