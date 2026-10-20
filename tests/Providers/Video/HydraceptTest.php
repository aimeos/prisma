<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


    public function testImagine() : void
    {
        $this->hydraceptJob( 'video', 'video.generate.v1', 'video/mp4', 'MP4' );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'Ocean waves', [
            'references' => [Image::fromBinary( 'REF', 'image/png' )],
        ], [
            'duration' => 8,
            'aspectRatio' => '16:9',
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 8, $body['input']['durationSeconds'] );
        $this->assertSame( '16:9', $body['input']['aspectRatio'] );
        $this->assertSame( 'MP4', $response->binary() );
        $this->assertSame( 'rcpt-1', $response->meta()['receiptId'] );
    }
}
