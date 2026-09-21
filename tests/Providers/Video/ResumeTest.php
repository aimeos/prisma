<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ResumeTest extends TestCase
{
    use MakesPrismaRequests;


    public static function providers() : array
    {
        return [
            'alibaba' => ['alibaba', 'task-1', 'https://dashscope-intl.aliyuncs.com/api/v1/tasks/task-1'],
            'bedrock' => ['bedrock', 'arn:aws:bedrock:us-east-1:123:async-invoke/job-1',
                'https://bedrock-runtime.us-east-1.amazonaws.com/async-invoke/arn%3Aaws%3Abedrock%3Aus-east-1%3A123%3Aasync-invoke%2Fjob-1'],
            'byteplus' => ['byteplus', 'task-1', 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/task-1'],
            'luma' => ['luma', 'gen-1', 'https://agents.lumalabs.ai/v1/generations/gen-1'],
            'minimax' => ['minimax', 'task-1', 'https://api.minimax.io/v1/query/video_generation?task_id=task-1'],
            'openrouter' => ['openrouter', 'job-1', 'https://openrouter.ai/api/v1/videos/job-1', ['status' => 'pending']],
            'runway' => ['runway', 'task-1', 'https://api.dev.runwayml.com/v1/tasks/task-1'],
            'veo' => ['veo', 'models/veo-3.1-generate-preview/operations/op-1',
                'https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-generate-preview/operations/op-1'],
            'xai' => ['xai', 'video-1', 'https://api.x.ai/v1/videos/video-1'],
        ];
    }


    #[DataProvider( 'providers' )]
    public function testResume( string $name, string $jobId, string $url, array $pending = [] ) : void
    {
        $this->prisma( 'video', $name, ['api_key' => 'test'] )->response( $pending );

        $response = $this->provider()->ensure( 'resume' )->resume( $jobId );

        $this->assertFalse( $response->ready() );
        $this->assertSame( $jobId, $response->jobId() );
        $this->assertSame( $url, (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['alibaba'] )]
    #[TestWith( ['bedrock'] )]
    #[TestWith( ['byteplus'] )]
    #[TestWith( ['luma'] )]
    #[TestWith( ['minimax'] )]
    #[TestWith( ['openrouter'] )]
    #[TestWith( ['runway'] )]
    #[TestWith( ['veo'] )]
    #[TestWith( ['xai'] )]
    public function testResumeInvalidJobId( string $name ) : void
    {
        $this->prisma( 'video', $name, ['api_key' => 'test'] );
        $provider = $this->provider();

        // dot segments would escape the status endpoint when the URL is resolved
        foreach( ['', '.', '..'] as $jobId )
        {
            try {
                $provider->resume( $jobId );
                $this->fail( 'BadRequestException expected for "' . $jobId . '"' );
            } catch( BadRequestException $e ) {
                $this->assertNotEmpty( $e->getMessage() );
            }
        }
    }


    #[TestWith( ['files?pageSize=100'] )]
    #[TestWith( ['operations/../files'] )]
    #[TestWith( ['models/../files/operations/op-1'] )]
    #[TestWith( ['models/veo-3.1-generate-preview/operations/op-1?alt=media'] )]
    #[TestWith( ['/operations/op-1'] )]
    public function testRejectsInvalidJobId( string $jobId ) : void
    {
        $this->prisma( 'video', 'veo', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( $jobId );
    }


    public function testResumePendingMeta() : void
    {
        $this->prisma( 'video', 'runway', ['api_key' => 'test'] )->response( ['status' => 'RUNNING', 'progress' => 0.5] );

        $response = $this->provider()->resume( 'task-1' );

        $this->assertFalse( $response->ready() );
        $this->assertSame( 0.5, $response->meta()['progress'] );
    }


    public function testResumeFailed() : void
    {
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )->response( ['status' => 'failed', 'message' => 'moderated'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'moderated' );
        $this->provider()->resume( 'video-1' )->ready();
    }


    public function testResumeCancelled() : void
    {
        // a canceled Runway task never succeeds, so it must not be polled forever
        $this->prisma( 'video', 'runway', ['api_key' => 'test'] )->response( ['status' => 'CANCELLED'] );

        $this->expectException( FailedException::class );
        $this->provider()->resume( 'task-1' )->ready();
    }


    #[TestWith( ['runway', 'task-1', '30', 30] )]
    #[TestWith( ['runway', 'task-1', 'Thu, 01 Jan 1970 00:00:00 GMT', 0] )]
    #[TestWith( ['runway', 'task-1', '', null] )]
    #[TestWith( ['bedrock', 'arn:aws:bedrock:us-east-1:123:async-invoke/job-1', '30', 30] )]
    public function testResumeRateLimited( string $name, string $jobId, string $header, ?int $expected ) : void
    {
        $headers = $header !== '' ? ['Retry-After' => $header] : [];
        $this->prisma( 'video', $name, ['api_key' => 'test'] )->response( ['message' => 'slow down'], $headers, 429 );

        try {
            $this->provider()->resume( $jobId )->ready();
            $this->fail( 'Expected a rate limit exception' );
        } catch( RateLimitException $e ) {
            $this->assertSame( $expected, $e->retryAfter() );
            $this->assertStringContainsString( rawurlencode( $jobId ), (string) $this->requests()[0]->getUri() );
        }
    }


    public function testNotResumable() : void
    {
        $this->assertFalse( $this->prisma( 'video', 'omni', ['api_key' => 'test'] )->provider()->has( 'resume' ) );
    }


    public function testQueuedResponse() : void
    {
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )->response( ['request_id' => 'video-1'] );
        $jobId = (string) $this->provider()->imagine( 'prompt' )->jobId();

        // queued job: create the provider again and resume polling the job
        $this->prisma( 'video', 'xai', ['api_key' => 'test'] )
            ->response( ['status' => 'done', 'video' => ['url' => 'https://example.com/video.mp4']] );

        $video = $this->provider()->resume( $jobId );

        $this->assertSame( 'video-1', $jobId );
        $this->assertTrue( $video->ready() );
        $this->assertSame( 'https://example.com/video.mp4', $video->first()?->url() );
    }
}
