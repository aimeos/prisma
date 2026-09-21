<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\OverloadedException;
use Aimeos\Prisma\Exceptions\PaymentRequiredException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Exceptions\UnauthorizedException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MinimaxTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyDropsReferencesWhenFramesAreUsed() : void
    {
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['task_id' => 'task-1'] );
        $this->response( ['status' => 'Success', 'file_id' => 42] );
        $this->response( ['file' => ['download_url' => 'https://example.com/video.mp4']] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'MiniMax-Hailuo-02', $body['model'] );
        $this->assertArrayNotHasKey( 'subject_reference', $body );
        $this->assertStringNotContainsString( 'reference.png', (string) $this->requests()[0]->getBody() );
    }


    public function testReferenceImagesSelectSubjectModel() : void
    {
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['task_id' => 'task-1'] );

        $this->provider()->imagine( 'prompt', [
            'references' => [Image::fromUrl( 'https://example.com/reference.png', 'image/png' )],
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'S2V-01', $body['model'] );
        $this->assertSame( ['https://example.com/reference.png'], $body['subject_reference'][0]['image'] );
    }


    #[TestWith( [1001, OverloadedException::class] )]
    #[TestWith( [1002, RateLimitException::class] )]
    #[TestWith( [1039, RateLimitException::class] )]
    #[TestWith( [1004, UnauthorizedException::class] )]
    #[TestWith( [1008, PaymentRequiredException::class] )]
    #[TestWith( [1026, BadRequestException::class] )]
    #[TestWith( [1000, PrismaException::class] )]
    #[TestWith( ['1002', RateLimitException::class] )]
    public function testImagineError( int|string $code, string $exception ) : void
    {
        // MiniMax reports errors with HTTP status 200 and the error code in "base_resp"
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['task_id' => '', 'base_resp' => ['status_code' => $code, 'status_msg' => 'error']] );

        try {
            $this->provider()->imagine( 'prompt' );
            $this->fail( 'Exception expected' );
        } catch( PrismaException $e ) {
            $this->assertInstanceOf( $exception, $e );
            $this->assertNotInstanceOf( FailedException::class, $e );
        }
    }


    public function testPollFailed() : void
    {
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['status' => 'Fail', 'base_resp' => ['status_code' => 1027, 'status_msg' => 'output sensitive']] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'output sensitive' );
        $this->provider()->resume( 'task-1' )->ready();
    }


    public function testPollFailedQuerySuccess() : void
    {
        // the status message belongs to the successful query, not to the failed task
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['status' => 'Fail', 'base_resp' => ['status_code' => 0, 'status_msg' => 'success']] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Video generation failed' );
        $this->provider()->resume( 'task-1' )->ready();
    }


    #[TestWith( [1026] )]
    #[TestWith( ['1027'] )]
    public function testPollSensitive( int|string $code ) : void
    {
        // sensitive content fails the task even if the status isn't "Fail"
        $this->prisma( 'video', 'minimax', ['api_key' => 'test'] )
            ->response( ['status' => 'Processing', 'base_resp' => ['status_code' => $code, 'status_msg' => 'sensitive']] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'sensitive' );
        $this->provider()->resume( 'task-1' )->ready();
    }


    public function testRetrieveRateLimited() : void
    {
        $prisma = $this->prisma( 'video', 'minimax', ['api_key' => 'test'] );
        $prisma->response( ['status' => 'Success', 'file_id' => 42] );
        $prisma->response( ['base_resp' => ['status_code' => 1002, 'status_msg' => 'rate limit']], ['Retry-After' => '10'] );
        $prisma->response( ['status' => 'Success', 'file_id' => 42] );
        $prisma->response( ['file' => ['download_url' => 'https://example.com/video.mp4']] );

        $response = $this->provider()->resume( 'task-1' );

        try {
            $response->ready();
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 'rate limit', $e->getMessage() );
            $this->assertSame( 10, $e->retryAfter() );
        }

        // the finished video isn't lost because of a rate limit when retrieving it
        $this->assertTrue( $response->ready() );
        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
    }
}
