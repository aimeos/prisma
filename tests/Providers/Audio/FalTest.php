<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class FalTest extends TestCase
{
    use MakesPrismaRequests;

    private const URL = 'https://queue.fal.run/fal-ai/elevenlabs/requests/req-1';


    public function testSpeak() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['request_id' => 'req-1', 'response_url' => self::URL, 'status' => 'IN_QUEUE'] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['audio' => ['url' => 'https://v3.fal.media/files/a.mp3', 'content_type' => 'audio/mpeg'], 'timestamps' => null] );

        $response = $this->provider()->ensure( 'speak' )->speak( 'Hello', 'Aria', ['stability' => 0.4] );

        $this->assertTrue( $response->ready() );
        $this->assertSame( self::URL, $response->jobId() );
        $this->assertSame( 'https://v3.fal.media/files/a.mp3', $response->first()?->url() );
        $this->assertSame( 'audio/mpeg', $response->first()?->mimeType() );
        $this->assertFalse( isset( $response->meta()['audio'] ) );

        $requests = $this->requests();
        $this->assertSame( 'https://queue.fal.run/fal-ai/elevenlabs/tts/eleven-v3', (string) $requests[0]->getUri() );
        $this->assertSame( 'Key test', $requests[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( ['stability' => 0.4, 'text' => 'Hello', 'voice' => 'Aria'], json_decode( (string) $requests[0]->getBody(), true ) );
        $this->assertSame( self::URL . '/status', (string) $requests[1]->getUri() );
        $this->assertSame( self::URL, (string) $requests[2]->getUri() );
    }


    public function testSpeakDefaultVoice() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->speak( 'Hello' );

        $this->assertSame( ['text' => 'Hello'], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testCompose() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'compose' )->compose( 'Calm piano', ['duration' => 30, 'force_instrumental' => true] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/elevenlabs/music', (string) $this->requests()[0]->getUri() );
        $this->assertSame(
            ['duration' => 30, 'force_instrumental' => true, 'prompt' => 'Calm piano', 'music_length_ms' => 30000],
            json_decode( (string) $this->requests()[0]->getBody(), true )
        );
    }


    public function testSynthesize() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'synthesize' )->synthesize( 'Thunder', ['duration' => 5] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/elevenlabs/sound-effects/v2', (string) $this->requests()[0]->getUri() );
        $this->assertSame( ['duration' => 5, 'text' => 'Thunder', 'duration_seconds' => 5], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testRevoice() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'revoice' )->revoice( Audio::fromBinary( 'a', 'audio/mpeg' ), 'Rachel' );

        $this->assertSame( 'https://queue.fal.run/fal-ai/elevenlabs/voice-changer', (string) $this->requests()[0]->getUri() );
        $this->assertSame(
            ['audio_url' => 'data:audio/mpeg;base64,' . base64_encode( 'a' ), 'voice' => 'Rachel'],
            json_decode( (string) $this->requests()[0]->getBody(), true )
        );
    }


    public function testDenoise() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );
        $this->response( ['status' => 'COMPLETED'] );
        $this->response( ['audio_file' => ['url' => 'https://fal.media/files/a.mp3'], 'timings' => ['inference' => 1.2]] );

        $response = $this->provider()->ensure( 'denoise' )->denoise( Audio::fromUrl( 'https://example.com/a.mp3', 'audio/mpeg' ) );

        $this->assertSame( 'https://fal.media/files/a.mp3', $response->first()?->url() );
        $this->assertSame( ['inference' => 1.2], $response->meta()['timings'] );
        $this->assertSame( 'https://queue.fal.run/fal-ai/deepfilternet3', (string) $this->requests()[0]->getUri() );
        $this->assertSame( ['audio_url' => 'https://example.com/a.mp3'], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testDescribe() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['output' => 'Ein Hund bellt', 'usage' => ['total_tokens' => 12]] );

        $response = $this->provider()->ensure( 'describe' )
            ->describe( Audio::fromUrl( 'https://example.com/a.mp3' ), 'de', ['max_tokens' => 50] );

        $this->assertSame( 'Ein Hund bellt', $response->text() );
        $this->assertSame( 12.0, $response->usage()['used'] );

        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'https://fal.run/openrouter/router/audio', (string) $request->getUri() );
        $this->assertSame( 'google/gemini-3.8-flash', $body['model'] );
        $this->assertSame( 'https://example.com/a.mp3', $body['audio_url'] );
        $this->assertSame( 50, $body['max_tokens'] );
        $this->assertStringContainsString( '"de"', $body['prompt'] );
    }


    #[TestWith( [1, 'htdemucs', ['vocals']] )]
    #[TestWith( [2, 'htdemucs', ['vocals', 'drums', 'bass', 'other']] )]
    #[TestWith( [6, 'htdemucs_6s', ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other']] )]
    public function testDemix( int $stems, string $model, array $expected ) : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->ensure( 'demix' )->demix( Audio::fromUrl( 'https://example.com/a.mp3', 'audio/mpeg' ), $stems );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'https://queue.fal.run/fal-ai/demucs', (string) $this->requests()[0]->getUri() );
        $this->assertSame( $model, $body['model'] );
        $this->assertSame( $expected, $body['stems'] );
        $this->assertSame( 'https://example.com/a.mp3', $body['audio_url'] );
    }


    public function testDemixOptions() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => self::URL] );

        $this->provider()->demix( Audio::fromUrl( 'https://example.com/a.mp3', 'audio/mpeg' ), 4, ['model' => 'htdemucs_ft'] );

        $this->assertSame( 'htdemucs_ft', json_decode( (string) $this->requests()[0]->getBody(), true )['model'] );
    }


    public function testDemixStems() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( [
            'vocals' => ['url' => 'https://fal.media/files/vocals.mp3', 'content_type' => 'audio/mpeg'],
            'drums' => ['url' => 'https://fal.media/files/drums.mp3', 'content_type' => 'audio/mpeg'],
            'guitar' => null,
        ] );

        $response = $this->provider()->resume( self::URL );

        $this->assertSame( ['vocals', 'drums'], array_keys( $response->files() ) );
        $this->assertSame( 'https://fal.media/files/drums.mp3', $response->files()['drums']->url() );
    }


    public function testModel() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['response_url' => 'https://queue.fal.run/fal-ai/minimax-music/requests/req-1'] );

        $this->provider()->model( 'fal-ai/minimax-music/v2' )->compose( 'Jazz', ['lyrics_prompt' => '[Instrumental]'] );

        $this->assertSame( 'https://queue.fal.run/fal-ai/minimax-music/v2', (string) $this->requests()[0]->getUri() );
    }


    public function testTranscribe() : void
    {
        $words = [['text' => 'Hallo', 'type' => 'word', 'start' => 0.1, 'end' => 0.5, 'speaker_id' => 'speaker_0']];

        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['text' => 'Hallo', 'words' => $words, 'language_code' => 'deu', 'language_probability' => 0.98] );

        $response = $this->provider()->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'a', 'audio/mpeg' ), 'de', ['diarize' => false] );

        $this->assertSame( 'Hallo', $response->text() );
        $this->assertSame( $words, $response->structured() );
        $this->assertSame( 'deu', $response->meta()['language_code'] );
        $this->assertFalse( isset( $response->meta()['words'] ) );

        $request = $this->requests()[0];
        $this->assertSame( 'https://fal.run/fal-ai/elevenlabs/speech-to-text/scribe-v2', (string) $request->getUri() );
        $this->assertSame( [
            'diarize' => false,
            'audio_url' => 'data:audio/mpeg;base64,' . base64_encode( 'a' ),
            'language_code' => 'de',
        ], json_decode( (string) $request->getBody(), true ) );
    }


    public function testTranscribeWhisper() : void
    {
        $chunks = [['timestamp' => [0.5, 1.2], 'text' => 'Hello']];

        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['text' => 'Hello', 'chunks' => $chunks, 'languages' => ['en']] );

        $response = $this->provider()->model( 'fal-ai/wizper' )
            ->transcribe( Audio::fromUrl( 'https://example.com/a.mp3' ) );

        $this->assertSame( $chunks, $response->structured() );
        $this->assertSame( 'https://fal.run/fal-ai/wizper', (string) $this->requests()[0]->getUri() );
        $this->assertSame( ['audio_url' => 'https://example.com/a.mp3'], json_decode( (string) $this->requests()[0]->getBody(), true ) );
    }


    public function testTranscribeNoText() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['detail' => 'x'] );

        $this->expectException( PrismaException::class );
        $this->provider()->transcribe( Audio::fromUrl( 'https://example.com/a.mp3' ) );
    }


    public function testFailed() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED', 'error' => 'Invalid voice'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Invalid voice' );
        $this->provider()->resume( self::URL )->ready();
    }


    public function testNoAudio() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED'] );
        $this->response( ['timestamps' => [['text' => 'a']]] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( self::URL )->ready();
    }


    public function testCancel() : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] )
            ->response( ['status' => 'CANCELLATION_REQUESTED'], [], 202 );

        $this->provider()->ensure( 'cancel' )->cancel( self::URL );

        $this->assertSame( 'PUT', $this->requests()[0]->getMethod() );
        $this->assertSame( self::URL . '/cancel', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( [''] )]
    #[TestWith( ['https://attacker.example/fal-ai/elevenlabs/requests/req-1'] )]
    #[TestWith( ['https://queue.fal.run/fal-ai/elevenlabs/requests/req-1/status'] )]
    public function testRejectsForeignUrl( string $jobId ) : void
    {
        $this->prisma( 'audio', 'fal', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( $jobId );
    }
}
