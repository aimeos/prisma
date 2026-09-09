<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\HasImageResponse;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Z as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Z extends Base implements Imagine
{
    use HasImageResponse;


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $options = $this->sanitize(
            $this->allowed( $options, ['quality', 'size', 'user_id'] ),
            ['quality' => ['hd', 'standard']]
        );

        $response = $this->client()->post( 'api/paas/v4/images/generations', [
            'json' => ['model' => $this->modelName( 'glm-image' ), 'prompt' => $prompt] + $options
        ] );

        return $this->toFileResponse( $response );
    }
}
