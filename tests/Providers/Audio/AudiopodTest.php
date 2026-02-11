<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AudiopodTest extends TestCase
{
    use MakesPrismaRequests;


    public function testSpeak() : void
    {
        $prisma = $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );
        $prisma->response( '{
            "job_id": "12345",
            "status": "PENDING"
        }' );
        $prisma->response( '{
            "job_id": "12345",
            "status": "PENDING"
        }' );
        $prisma->response( '{
            "job_id": "12345",
            "status": "COMPLETED",
            "output_url": "https://localhost/test.mp3"
        }' );

        $file = $prisma->response( 'MP3' )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'test' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.audiopod.ai/api/v1/voice/voices/test/generate', (string) $request->getUri() );
        } );

        $this->assertFalse( $file->ready() );
        $this->assertEquals( 'MP3', $file->binary() );
    }
}
