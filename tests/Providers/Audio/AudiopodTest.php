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


    public function testTranscribe() : void
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
                "text": "Hello"
            }' );

        $response = $prisma->response(
            json_encode( [
                'segments' => [[
                    'id' => 0,
                    'start' => 0.05,
                    'end' => 0.65,
                    'text' => 'Hello',
                    'language' => 'en',
                    'confidence' => 0.3515625,
                    'speaker_id' => 0,
                    'speaker_label' => 'SPEAKER_00'
                ]],
                'statistics' => [
                    'speaker_count' => 1
                ]
            ] ) )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.audiopod.ai/api/v1/transcription/transcribe-upload', (string) $request->getUri() );
        } );

        $this->assertFalse( $response->ready() );
        $this->assertEquals( 'Hello', $response->text() );
    }
}
