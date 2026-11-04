<?php

namespace Aimeos\Prisma\Files;

use Aimeos\Prisma\Exceptions\BadRequestException;


/**
 * Image file content.
 */
class Image extends File
{
    /** Bytes of URL images fetched to read the size from the image header */
    private const HEADER_BYTES = 64 * 1024;


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
        if( $this->url && $this->binary === null && $this->base64 === null && $this->stream === null ) {
            $size = @getimagesizefromstring( $this->fetch( $this->url, -self::HEADER_BYTES, $this->strict ) );
        }

        // the header can be larger, e.g. for JPEG images with big EXIF data
        if( empty( $size ) && ( $size = @getimagesizefromstring( (string) $this->binary() ) ) === false ) {
            throw new BadRequestException( 'Unable to get image size' );
        }

        return [$size[0], $size[1]];
    }


    protected function mimePrefix() : string
    {
        return 'image/';
    }
}
