<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Responses\TextResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ResumeTest extends TestCase
{
    use MakesPrismaRequests;


    public static function jobs() : array
    {
        return [
            'demix' => ['api/v1/stem-extraction/status/1'],
            'denoise' => ['api/v1/denoiser/jobs/job.1'],
            'revoice' => ['api/v1/voice/convert/1/status'],
            'speak' => ['api/v1/voice/tts-jobs/a1b2-c3d4/status'],
        ];
    }


    #[DataProvider( 'jobs' )]
    public function testResume( string $jobId ) : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['status' => 'PROCESSING', 'progress' => 50] );

        $response = $this->provider()->ensure( 'resume' )->resume( $jobId );

        $this->assertFalse( $response->ready() );
        $this->assertSame( $jobId, $response->jobId() );
        $this->assertSame( 50, $response->meta()['progress'] );
        $this->assertSame( 'https://api.audiopod.ai/' . $jobId, (string) $this->requests()[0]->getUri() );
    }


    public function testResumeFailed() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['status' => 'FAILED', 'error' => 'Invalid audio'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Invalid audio' );
        $this->provider()->resume( 'api/v1/denoiser/jobs/1' )->ready();
    }


    public function testResumeLowerCaseStatus() : void
    {
        // text to speech jobs report their status in lower case
        $prisma = $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );
        $prisma->response( ['status' => 'pending'] );
        $prisma->response( ['status' => 'completed', 'output_url' => 'https://media.audiopod.ai/a.mp3'] );

        $response = $this->provider()->resume( 'api/v1/voice/tts-jobs/1/status' );

        $this->assertFalse( $response->ready() );
        $this->assertTrue( $response->ready() );
        $this->assertSame( 'https://media.audiopod.ai/a.mp3', $response->first()?->url() );
    }


    public function testResumeLowerCaseFailed() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )
            ->response( ['status' => 'failed', 'error_message' => 'Voice not found', 'error' => null] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Voice not found' );
        $this->provider()->resume( 'api/v1/voice/tts-jobs/1/status' )->ready();
    }


    public function testResumeNoOutput() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['status' => 'COMPLETED'] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( 'api/v1/denoiser/jobs/1' )->ready();
    }


    public function testResumeInvalidOutput() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['status' => 'COMPLETED',
            'download_urls' => ['vocals' => 'https://media.audiopod.ai/v.mp3', 'drums' => ['x'], 'bass' => '']] );

        $response = $this->provider()->resume( 'api/v1/stem-extraction/status/1' );

        // entries that aren't URLs don't become files
        $this->assertTrue( $response->ready() );
        $this->assertSame( ['vocals'], array_keys( $response->files() ) );
    }


    public function testResumeNoValidOutput() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['status' => 'COMPLETED', 'output_url' => 123] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( 'api/v1/denoiser/jobs/1' )->ready();
    }


    public function testResumeEmptyJobId() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( '' );
    }


    #[TestWith( ['1'] )]
    #[TestWith( ['api/v1/transcription/jobs/1/status'] )]
    #[TestWith( ['api/v1/transcription/transcript/1'] )]
    #[TestWith( ['https://attacker.example/api/v1/denoiser/jobs/1'] )]
    #[TestWith( ['api/v1/denoiser/jobs/../../../api/v1/account/me'] )]
    #[TestWith( ['api/v1/denoiser/jobs/1/../../../account/me'] )]
    #[TestWith( ['api/v1/denoiser/jobs/1?page=2'] )]
    #[TestWith( ['api/v1/voice/convert/1'] )]
    #[TestWith( ['api/v1/voice/tts-jobs/../status'] )]
    #[TestWith( ['api/v1/denoiser/jobs/%2e%2e'] )]
    #[TestWith( ['api/v1/transcription/jobs/.%2E'] )]
    public function testRejectsInvalidJobId( string $jobId ) : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( $jobId );
    }


    public function testResumeTranscription() : void
    {
        $prisma = $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );
        $prisma->response( ['job_id' => '1', 'status' => 'COMPLETED'] );
        $prisma->response( ['segments' => [['text' => 'Hello']], 'statistics' => ['speaker_count' => 1]] );

        $response = $this->provider()->ensure( 'resume' )->resume( 'api/v1/transcription/jobs/1' );

        // transcriptions are resumed as text response instead of a file response
        $this->assertInstanceOf( TextResponse::class, $response );
        $this->assertTrue( $response->ready() );
        $this->assertSame( 'Hello', $response->text() );
        $this->assertSame( 'api/v1/transcription/jobs/1', $response->jobId() );
        $this->assertSame( 'COMPLETED', $response->meta()['status'] );
        $this->assertSame( 1, $response->meta()['speaker_count'] );
        $this->assertSame( 'https://api.audiopod.ai/api/v1/transcription/transcript/1', (string) $this->requests()[1]->getUri() );
    }


    #[TestWith( ['api/v1/transcription/jobs/%61bc', 'api/v1/transcription/jobs/abc'] )]
    #[TestWith( ['api/v1/denoiser/jobs/%61bc', 'api/v1/denoiser/jobs/abc'] )]
    public function testResumeNormalizesJobId( string $jobId, string $expected ) : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );

        // the same job has the same job ID, whether it's resumed or returned by the request
        $this->assertSame( $expected, $this->provider()->resume( $jobId )->jobId() );
    }


    public function testQueuedTranscription() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['job_id' => '12345', 'status' => 'PENDING'] );
        $jobId = (string) $this->provider()->ensure( 'transcribe' )
            ->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ) )->jobId();

        // queued job: create the provider again and resume polling the transcription
        $prisma = $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );
        $prisma->response( ['job_id' => '12345', 'status' => 'COMPLETED'] );
        $prisma->response( ['segments' => [['text' => 'Hello']]] );

        $response = $this->provider()->resume( $jobId );

        $this->assertSame( 'api/v1/transcription/jobs/12345', $jobId );
        $this->assertTrue( $response->ready() );
        $this->assertSame( 'Hello', $response->text() );
    }


    public function testQueuedOpaqueJobId() : void
    {
        // submitted jobs use the ID of the provider instead of validating caller input, but encoded
        // so it can't change the status path
        $prisma = $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] );
        $prisma->response( ['id' => 'v2+job/1?x', 'status' => 'PENDING'] );
        $prisma->response( ['status' => 'PROCESSING'] );
        $prisma->response( ['status' => 'PROCESSING'] );

        $response = $this->provider()->denoise( Audio::fromBinary( 'MP3', 'audio/mpeg' ) );

        $this->assertSame( 'api/v1/denoiser/jobs/v2%2Bjob%2F1%3Fx', $response->jobId() );
        $this->assertFalse( $response->ready() );
        $this->assertSame( 'https://api.audiopod.ai/api/v1/denoiser/jobs/v2%2Bjob%2F1%3Fx', (string) $this->requests()[1]->getUri() );

        // the encoded job ID can be resumed too
        $resumed = $this->provider()->resume( (string) $response->jobId() );

        $this->assertSame( $response->jobId(), $resumed->jobId() );
        $this->assertFalse( $resumed->ready() );
        $this->assertSame( 'https://api.audiopod.ai/api/v1/denoiser/jobs/v2%2Bjob%2F1%3Fx', (string) $this->requests()[2]->getUri() );
    }


    public function testNotResumable() : void
    {
        $this->assertFalse( $this->prisma( 'audio', 'openai', ['api_key' => 'test'] )->provider()->has( 'resume' ) );
    }


    public function testQueuedResponse() : void
    {
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )->response( ['id' => '12345', 'status' => 'PENDING'] );
        $jobId = (string) $this->provider()->denoise( Audio::fromBinary( 'MP3', 'audio/mpeg' ) )->jobId();

        // queued job: create the provider again and resume polling the job
        $this->prisma( 'audio', 'audiopod', ['api_key' => 'test'] )
            ->response( ['status' => 'COMPLETED', 'output_url' => 'https://media.audiopod.ai/a.mp3'] );

        $audio = $this->provider()->resume( $jobId );

        $this->assertSame( 'api/v1/denoiser/jobs/12345', $jobId );
        $this->assertTrue( $audio->ready() );
        $this->assertSame( 'https://media.audiopod.ai/a.mp3', $audio->first()?->url() );
    }
}
