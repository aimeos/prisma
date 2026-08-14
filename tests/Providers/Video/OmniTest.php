<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OmniTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyFiltersUnsupportedMedia() : void
    {
        $response = $this->prisma( 'video', 'omni', ['api_key' => 'test'] )
            ->response( ['steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'video',
                    'mime_type' => 'video/mp4',
                    'data' => base64_encode( 'MP4' ),
                ]],
            ]]] )
            ->ensure( 'imagine' )
            ->imagine( 'prompt', [
                'start' => Image::fromBinary( 'START', 'image/png' ),
                'end' => Image::fromBinary( 'END', 'image/png' ),
                'references' => [
                    Image::fromBinary( 'REFERENCE', 'image/png' ),
                    Audio::fromBinary( 'AUDIO', 'audio/mpeg' ),
                    Video::fromBinary( 'VIDEO', 'video/mp4' ),
                ],
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $this->assertSame( 'gemini-omni-flash-preview', $body['model'] );
            $this->assertSame( 'image_to_video', $body['generation_config']['video_config']['task'] );
            $this->assertCount( 2, $body['input'] );
            $this->assertSame( base64_encode( 'START' ), $body['input'][0]['data'] );
            $this->assertStringNotContainsString( base64_encode( 'REFERENCE' ), (string) $request->getBody() );
            $this->assertStringNotContainsString( base64_encode( 'END' ), (string) $request->getBody() );
            $this->assertStringNotContainsString( base64_encode( 'AUDIO' ), (string) $request->getBody() );
            $this->assertStringNotContainsString( base64_encode( 'VIDEO' ), (string) $request->getBody() );
        } );

        $this->assertSame( 'MP4', $response->binary() );
    }
}
