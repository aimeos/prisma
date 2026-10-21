<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


    public function testImagine() : void
    {
        $this->hydraceptJob( 'image', 'image.generate.v1', 'image/png', 'PNG' );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'A red balloon', [
            Image::fromBinary( 'REF', 'image/jpeg' ),
        ], [
            'width' => 1024,
            'height' => 768,
            'quality' => 'high',
            'outputFormatPreference' => 'ignored',
            'idempotencyKey' => 'idem-1',
            'billingMode' => 'managed',
            'externalRef' => 'order-1',
        ] );

        $job = json_decode( (string) $this->requests()[0]->getBody(), true );

        $this->assertSame(
            'https://api.hydracept.com/v1/capabilities/image.generate.v1/jobs',
            (string) $this->requests()[0]->getUri()
        );
        $this->assertSame( 'Bearer test-key', $this->requests()[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( 'proj-1', $job['context']['projectId'] );
        $this->assertSame( 'A red balloon', $job['input']['prompt'] );
        $this->assertSame( 1024, $job['input']['width'] );
        $this->assertArrayNotHasKey( 'filename', $job['input']['referenceImages'][0] );
        $this->assertSame( 'order-1', $job['externalRef'] );
        $this->assertSame( 'idem-1', $job['idempotencyKey'] );
        $this->assertSame( ['billingMode' => 'managed'], $job['execution'] );
        $this->assertArrayNotHasKey( 'outputFormatPreference', $job['input'] );

        $this->assertSame( 'PNG', $response->binary() );
        $this->assertSame( 'job-1', $response->jobId() );
        $this->assertSame( 'rcpt-1', $response->usage()['receiptId'] );
        $this->assertArrayNotHasKey( 'pricing', $response->meta()->all() );
        $this->assertSame( 0.12, $response->usage()['used'] );
        $this->assertSame( 150000, $response->usage()['pricing']['charge']['customerCharge']['amountMicros'] );
        $this->assertSame( 120000, $response->usage()['pricing']['providerCost']['amount']['amountMicros'] );
    }


    public static function statuses() : array
    {
        return [
            'needs attention' => [['status' => 'needs_attention', 'error' => 'Policy review'], 'Policy review'],
            'needs attention, poll' => [['status' => 'needs_attention', 'nextAction' => 'poll'], null],
            'next action stop' => [['status' => 'running', 'nextAction' => 'retry_new', 'error' => 'Provider lost'], 'Provider lost'],
            'unknown status' => [['status' => 'throttled'], null],
        ];
    }


    #[DataProvider( 'statuses' )]
    public function testImagineStatus( array $job, ?string $error ) : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1'] + $job );

        // jobs fail if Hydracept advises to stop, otherwise they are polled again
        if( $error !== null ) {
            $this->expectException( FailedException::class );
            $this->expectExceptionMessage( $error );
        }

        $this->assertFalse( $this->provider()->imagine( 'A red balloon' )->ready() );
    }


    #[TestWith( [12, 12] )]
    #[TestWith( [0, 5] )]
    #[TestWith( ['12', 12] )]
    #[TestWith( [2.5, 2] )]
    public function testImaginePollAfter( mixed $value, int $expected ) : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'running', 'nextAction' => 'poll', 'pollAfterSeconds' => $value] );

        $response = $this->provider()->imagine( 'A red balloon' );

        $this->assertFalse( $response->ready() );
        $this->assertSame( $expected, $response->retryAfter() );
    }


    public function testImaginePollAfterReset() : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'running', 'nextAction' => 'poll', 'pollAfterSeconds' => 60] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'running'] );

        $response = $this->provider()->imagine( 'A red balloon' );

        // the interval requested by Hydracept isn't kept for later polls
        $this->assertFalse( $response->ready() );
        $this->assertSame( 60, $response->retryAfter() );
        $this->assertFalse( $response->ready() );
        $this->assertSame( 5, $response->retryAfter() );
    }


    public function testImagineRetriesDownloads() : void
    {
        $succeeded = ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'art-1', 'mediaType' => 'image/png'],
            ['artifactId' => 'art-2', 'mediaType' => 'image/png'],
        ]];

        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( $succeeded );
        $prisma->response( 'PNG1', ['Content-Type' => 'image/png'] );
        $prisma->response( ['error' => 'Too many requests'], [], 429 );
        $prisma->response( $succeeded );
        $prisma->response( 'PNG1', ['Content-Type' => 'image/png'] );
        $prisma->response( 'PNG2', ['Content-Type' => 'image/png'] );

        $response = $this->provider()->imagine( 'A red balloon' );

        try {
            $response->ready();
            $this->fail( 'Expected rate limit exception' );
        } catch( RateLimitException $e ) {}

        // files of the failed attempt aren't added twice
        $this->assertTrue( $response->ready() );
        $this->assertCount( 2, $response->files() );
    }


    public function testImagineTooLarge() : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'art-1', 'mediaType' => 'image/png'],
        ]] );
        $prisma->response( str_repeat( 'x', 201 ), ['Content-Type' => 'image/png'] );

        $response = $this->provider()->withMaxResponseSize( 200 )->imagine( 'A red balloon' );

        try {
            $response->ready();
            $this->fail( 'Expected failed exception' );
        } catch( FailedException $e ) {
            $this->assertStringContainsString( 'exceeds the maximum allowed size', $e->getMessage() );
        }

        // oversized artifacts won't change, so the job isn't polled and downloaded again
        try {
            $response->ready();
            $this->fail( 'Expected failed exception' );
        } catch( FailedException ) {}

        $this->assertCount( 3, $this->requests() );
    }


    public function testImagineArtifactNotFound() : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'art-1', 'mediaType' => 'image/png'],
        ]] );
        $prisma->response( ['error' => 'Artifact expired'], [], 410 );

        $response = $this->provider()->imagine( 'A red balloon' );

        try {
            $response->ready();
            $this->fail( 'Expected failed exception' );
        } catch( FailedException $e ) {
            $this->assertSame( 'Artifact expired', $e->getMessage() );
        }

        // missing artifacts won't appear again, so no further requests are sent
        try {
            $response->ready();
            $this->fail( 'Expected failed exception' );
        } catch( FailedException ) {}

        $this->assertCount( 3, $this->requests() );
    }


    public function testImagineModel() : void
    {
        $this->hydraceptJob( 'image', 'image.generate.v1', 'image/png', 'PNG' );

        $this->provider()->model( 'gpt-image-2.5-sunburst' )->imagine( 'A red balloon' )->ready();

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'gpt-image-2.5-sunburst', $body['input']['model'] );
    }


    #[TestWith( [120, 120] )]
    #[TestWith( ['120', 120] )]
    #[TestWith( ['-1', 900] )]
    #[TestWith( ['2m', 900] )]
    #[TestWith( [-1, 900] )]
    public function testPollTimeout( mixed $value, int $expected ) : void
    {
        $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key', 'poll_timeout' => $value] );

        $this->assertSame( $expected, ( new \ReflectionProperty( $this->provider(), 'pollTimeout' ) )->getValue( $this->provider() ) );
    }


    public function testImagineArtifactTypes() : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'meta', 'mediaType' => 'application/json'],
            ['artifactId' => '..', 'mediaType' => 'image/png'],
            ['artifactId' => 'art-1', 'mediaType' => 'Image/PNG'],
        ]] );
        $prisma->response( 'PNG' );

        $response = $this->provider()->imagine( 'A red balloon' );

        // only artifacts of the expected media type and with a usable ID are downloaded
        $this->assertTrue( $response->ready() );
        $this->assertCount( 1, $response->files() );
        $this->assertSame( 'image/png', $response->first()?->mimeType() );
        $this->assertCount( 3, $this->requests() );
        $this->assertStringEndsWith( '/artifacts/art-1', (string) $this->requests()[2]->getUri() );
    }


    public function testImagineArtifactGeneric() : void
    {
        $png = (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' );
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'art-1', 'mediaType' => 'application/octet-stream'],
            ['artifactId' => 'art-2'],
        ]] );
        $prisma->response( $png, ['Content-Type' => 'application/octet-stream'] );
        $prisma->response( $png, ['Content-Type' => 'application/octet-stream'] );

        $response = $this->provider()->imagine( 'A red balloon' );

        // untyped and generic artifacts are downloaded and their media type is detected from the content
        $this->assertTrue( $response->ready() );
        $this->assertCount( 2, $response->files() );

        foreach( $response->files() as $file ) {
            $this->assertSame( 'image/png', $file->mimeType() );
        }
    }


    public function testImagineArtifactTypesNone() : void
    {
        $prisma = $this->prisma( 'image', 'hydracept', ['api_key' => 'test-key'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'queued'] );
        $prisma->response( ['jobId' => 'job-1', 'status' => 'succeeded', 'artifacts' => [
            ['artifactId' => 'meta', 'mediaType' => 'application/json'],
        ]] );

        $this->expectException( FailedException::class );
        $this->provider()->imagine( 'A red balloon' )->ready();
    }
}
