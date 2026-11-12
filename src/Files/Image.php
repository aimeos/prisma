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
     * Images only available by URL aren't downloaded completely if their header contains the size.
     *
     * @return array{0: int, 1: int} Width and height in pixels
     * @throws BadRequestException If the image size can't be determined
     */
    public function size() : array
    {
        $size = @getimagesizefromstring( $this->head() );

        // the header can be larger, e.g. for JPEG images with big EXIF data
        if( $size === false && $this->binary === null ) {
            $size = @getimagesizefromstring( (string) $this->binary() );
        }

        if( $size === false ) {
            throw new BadRequestException( 'Unable to get image size' );
        }

        return [$size[0], $size[1]];
    }


    protected function mimePrefix() : string
    {
        return 'image/';
    }
}
