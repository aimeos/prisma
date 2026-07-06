<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ZTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'audio', 'z', [] );
    }


    public function testTranscribe() : void
    {
        $response = $this->prisma( 'audio', 'z', ['api_key' => 'test'] )
            ->response( '{
                "text": "A test file"
            }' )
            ->ensure( 'transcribe' )
            ->transcribe(
                Audio::fromBinary( 'MP3', 'audio/mpeg' ),
                'en',
                [
                    'prompt' => 'Test prompt',
                    'hotwords' => 'AI,Speech',
                    'stream' => true,
                    'request_id' => 'request-123',
                    'user_id' => 'user-789',
                    'unknown' => 'ignored'
                ]
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://api.z.ai/api/paas/v4/audio/transcriptions', (string) $request->getUri() );

            $body = (string) $request->getBody();
            $this->assertStringContainsString( 'name="model"', $body );
            $this->assertStringContainsString( 'glm-asr-2512', $body );
            $this->assertStringContainsString( 'name="prompt"', $body );
            $this->assertStringContainsString( 'name="hotwords"', $body );
            $this->assertStringContainsString( 'name="stream"', $body );
            $this->assertStringContainsString( 'name="request_id"', $body );
            $this->assertStringContainsString( 'name="user_id"', $body );
            $this->assertStringNotContainsString( 'name="unknown"', $body );
        } );

        $this->assertEquals( 'A test file', $response->text() );
    }


    public function testTranscribeError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'audio', 'z', ['api_key' => 'test'] )
            ->response( ['error' => ['message' => 'Bad request']], status: 400, reason: 'Bad Request' )
            ->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ) );
    }
}

