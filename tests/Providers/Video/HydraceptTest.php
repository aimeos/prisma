<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


    public function testImagine() : void
    {
        $this->hydraceptJob( 'video', 'video.generate.v1', 'video/mp4', 'MP4' );

        $response = $this->provider()->ensure( 'imagine' )->model( 'veo-3' )->imagine( 'Ocean waves', [
            'references' => [Image::fromBinary( 'REF', 'image/png' )],
        ], [
            'duration' => 8,
            'aspectRatio' => '16:9',
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 8, $body['input']['durationSeconds'] );
        $this->assertSame( '16:9', $body['input']['aspectRatio'] );
        // Hydracept video has no model selection
        $this->assertArrayNotHasKey( 'model', $body['input'] );
        $this->assertSame( 'MP4', $response->binary() );
        $this->assertSame( 'rcpt-1', $response->usage()['receiptId'] );
    }


    public function testImagineReferences() : void
    {
        $this->hydraceptJob( 'video', 'video.generate.v1', 'video/mp4', 'MP4' );

        $this->provider()->imagine( 'Ocean waves', [
            'references' => [Video::fromBinary( 'VID', 'video/mp4' ), Image::fromBinary( 'REF', 'image/png' )],
        ], [
            'duration' => '5.6',
        ] )->ready();

        // only images are sent as references and the duration is rounded to whole seconds
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertCount( 1, $body['input']['referenceImages'] );
        $this->assertSame( 'image/png', $body['input']['referenceImages'][0]['mimeType'] );
        $this->assertSame( 6, $body['input']['durationSeconds'] );
    }


    public function testImagineInvalidDuration() : void
    {
        $this->hydraceptJob( 'video', 'video.generate.v1', 'video/mp4', 'MP4' );

        $this->provider()->imagine( 'Ocean waves', [], ['duration' => 'long'] )->ready();

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertArrayNotHasKey( 'durationSeconds', $body['input'] );
    }
}
