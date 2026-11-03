<?php

namespace Aimeos\Prisma\Files;

use Aimeos\Prisma\Exceptions\BadRequestException;


/**
 * Image file content.
 */
class Image extends File
{
    /**
     * Returns the width and height of the image.
     *
     * @return array{0: int, 1: int} Width and height in pixels
     * @throws BadRequestException If the image size can't be determined
     */
    public function size() : array
    {
        if( ( $size = @getimagesizefromstring( (string) $this->binary() ) ) === false ) {
            throw new BadRequestException( 'Unable to get image size' );
        }

        return [$size[0], $size[1]];
    }


    protected function mimePrefix() : string
    {
        return 'image/';
    }
}
