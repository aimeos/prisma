<?php

namespace Tests\Files;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;


class ImageTest extends TestCase
{
    public function testSize() : void
    {
        // 1x1 transparent PNG
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        $this->assertSame( [1, 1], Image::fromBase64( $png, 'image/png' )->size() );
    }


    public function testSizeInvalid() : void
    {
        $this->expectException( BadRequestException::class );

        Image::fromBinary( 'no image', 'image/png' )->size();
    }
}
