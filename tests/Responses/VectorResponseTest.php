<?php

namespace Tests\Responses;

use Aimeos\Prisma\Responses\VectorResponse;
use PHPUnit\Framework\TestCase;


class VectorResponseTest extends TestCase
{
    public function testFromVectors() : void
    {
        $response = VectorResponse::fromVectors( [[0.1, 0.2], [0.3, 0.4]] );

        $this->assertSame( [[0.1, 0.2], [0.3, 0.4]], $response->vectors() );
        $this->assertSame( [0.1, 0.2], $response->first() );
    }


    public function testJsonSerializeExposesVectors() : void
    {
        $data = VectorResponse::fromVectors( [[0.1, 0.2]] )->withUsage( 3 )->jsonSerialize();

        $this->assertSame( [[0.1, 0.2]], $data['vectors'] );
        $this->assertEquals( 3, $data['usage']['used'] );
        $this->assertSame( [], $data['meta']->all() );
    }
}
