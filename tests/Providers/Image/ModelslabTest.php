<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\OverloadedException;
use Aimeos\Prisma\Exceptions\PaymentRequiredException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Exceptions\UnauthorizedException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ModelslabTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $response = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/a.png']] )
            ->ensure( 'imagine' )
            ->imagine( 'a fox', [], ['width' => 512, 'unknown' => 'x'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://modelslab.com/api/v6/images/text2img', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'test', $body['key'] );
            $this->assertEquals( 'a fox', $body['prompt'] );
            $this->assertEquals( 512, $body['width'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
        } );

        $this->assertEquals( 'https://cdn.modelslab.com/a.png', $response->first()?->url() );
    }


    public function testImagineError() : void
    {
        // no job was created, so the error isn't a failed job and the request can be retried
        $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'error', 'message' => 'Rate limit exceeded'] );

        try {
            $this->provider()->imagine( 'x' );
            $this->fail( 'PrismaException expected' );
        } catch( PrismaException $e ) {
            $this->assertNotInstanceOf( FailedException::class, $e );
            $this->assertSame( 'Rate limit exceeded', $e->getMessage() );
        }
    }


    #[TestWith( ['rate_limited', RateLimitException::class] )]
    #[TestWith( ['invalid_api_key', UnauthorizedException::class] )]
    #[TestWith( ['insufficient_balance', PaymentRequiredException::class] )]
    #[TestWith( ['validation_error', BadRequestException::class] )]
    #[TestWith( ['upstream_unavailable', OverloadedException::class] )]
    #[TestWith( ['server_error', PrismaException::class] )]
    public function testImagineErrorCode( string $code, string $exception ) : void
    {
        // ModelsLab reports request errors with HTTP status 200 and the error code in the body
        $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'error', 'code' => $code, 'message' => 'error'] );

        try {
            $this->provider()->imagine( 'x' );
            $this->fail( 'PrismaException expected' );
        } catch( PrismaException $e ) {
            $this->assertInstanceOf( $exception, $e );
            $this->assertNotInstanceOf( FailedException::class, $e );
            $this->assertSame( 'error', $e->getMessage() );
        }
    }


    public function testFetchRateLimited() : void
    {
        $prisma = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] );
        $prisma->response( ['status' => 'error', 'code' => 'rate_limited', 'message' => 'Too many requests'], ['Retry-After' => '30'] );
        $prisma->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/a.png']] );

        $response = $this->provider()->resume( 'https://modelslab.com/api/v6/images/fetch/1' );

        try {
            $response->ready();
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 30, $e->retryAfter() );
        }

        // a rate limited fetch isn't a failed job, so it's polled again
        $this->assertSame( 30, $response->retryAfter() );
        $this->assertTrue( $response->ready() );
        $this->assertSame( 'https://cdn.modelslab.com/a.png', $response->first()?->url() );
    }


    public function testFetchProviderError() : void
    {
        $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'error', 'code' => 'provider_error', 'message' => 'Generation refused'] );

        $this->expectException( FailedException::class );
        $this->expectExceptionMessage( 'Generation refused' );
        $this->provider()->resume( 'https://modelslab.com/api/v6/images/fetch/1' )->ready();
    }


    public function testImagineNoFetchUrl() : void
    {
        $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'processing'] );

        // without a fetch URL and a request ID, the job can't be polled
        $this->expectException( FailedException::class );
        $this->provider()->imagine( 'x' );
    }


    #[TestWith( [42, 'https://modelslab.com/api/v6/images/fetch/42'] )]
    #[TestWith( ['job-1', 'https://modelslab.com/api/v6/images/fetch/job-1'] )]
    public function testImagineNoFetchUrlId( int|string $id, string $url ) : void
    {
        $prisma = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] );
        $prisma->response( ['status' => 'processing', 'id' => $id, 'fetch_result' => null] );
        $prisma->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/b.png']] );

        $response = $this->provider()->imagine( 'a cat' );

        // the fetch URL is optional, the request ID is polled on the fetch endpoint instead
        $this->assertSame( $url, $response->jobId() );
        $this->assertTrue( $response->ready() );
        $this->assertSame( $url, (string) $this->requests()[1]->getUri() );
    }


    public function testImagineProcessingPolls() : void
    {
        $provider = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'processing', 'id' => 1, 'fetch_result' => 'https://modelslab.com/api/v6/images/fetch/1', 'eta' => 1] );
        $this->response( ['status' => 'success', 'output' => ['https://cdn.modelslab.com/b.png']] );

        $response = $provider->ensure( 'imagine' )->imagine( 'a cat' );

        // the queued job is resolved by the fetch URL of the provider
        $this->assertSame( 'https://modelslab.com/api/v6/images/fetch/1', $response->jobId() );
        $this->assertSame( 1, $response->retryAfter() ); // eta of the provider
        $this->assertTrue( $response->ready() );
        $this->assertEquals( 'https://cdn.modelslab.com/b.png', $response->first()?->url() );
        // the fetch response updates the meta data of the submit response instead of replacing it
        $this->assertSame( 'success', $response->meta()['status'] );
        $this->assertSame( 1, $response->meta()['id'] );
        $this->assertSame( 'https://modelslab.com/api/v6/images/fetch/1', (string) $this->requests()[1]->getUri() );
    }


    public function testImagineStaleEta() : void
    {
        $provider = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] )
            ->response( ['status' => 'processing', 'fetch_result' => 'https://modelslab.com/api/v6/images/fetch/1', 'eta' => 120] );
        $this->response( ['status' => 'processing', 'eta' => 60] );
        $this->response( ['status' => 'processing'] );

        $response = $provider->imagine( 'a cat' );

        $this->assertSame( 60, $response->retryAfter() ); // estimates are limited to one minute
        $this->assertFalse( $response->ready() );
        $this->assertSame( 60, $response->retryAfter() );

        // without a new estimate, the job isn't polled by an outdated one
        $this->assertFalse( $response->ready() );
        $this->assertSame( 5, $response->retryAfter() );
    }


    public function testUnknownStatus() : void
    {
        $prisma = $this->prisma( 'image', 'modelslab', ['api_key' => 'test'] );
        $prisma->response( ['status' => 'expired'] );
        $prisma->response( ['status' => 'processing'] );

        $response = $this->provider()->resume( 'https://modelslab.com/api/v6/images/fetch/1' );

        // unknown states stop waiting for the job, but aren't reported as failed jobs
        try {
            $response->ready();
            $this->fail( 'PrismaException expected' );
        } catch( PrismaException $e ) {
            $this->assertNotInstanceOf( FailedException::class, $e );
        }

        $this->assertFalse( $response->ready() );
    }


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'modelslab', [] );
    }
}
