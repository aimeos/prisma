<?php

namespace Tests\Responses;

use Aimeos\Prisma\Responses\FileResponse;
use PHPUnit\Framework\TestCase;


class FileResponseTest extends TestCase
{
    public function testJsonSerializeExposesFileMetadataOnly() : void
    {
        $data = FileResponse::fromBinary( 'binarydata', 'image/png' )
            ->as( 'cat.png' )
            ->withDescription( 'a cat' )
            ->jsonSerialize();

        $this->assertEquals( 'a cat', $data['description'] );
        $this->assertCount( 1, $data['files'] );
        $this->assertEquals( 'cat.png', $data['files'][0]['filename'] );
        $this->assertEquals( 'image/png', $data['files'][0]['mimeType'] );

        // binary content is never part of the serialized form
        $this->assertArrayNotHasKey( 'binary', $data['files'][0] );
        $this->assertArrayNotHasKey( 'base64', $data['files'][0] );
    }
}
