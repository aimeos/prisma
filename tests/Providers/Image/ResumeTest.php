<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ResumeTest extends TestCase
{
    use MakesPrismaRequests;


    public static function failures() : array
    {
        return [
            'adobe' => ['adobe', 'https://firefly-api.adobe.io/v3/status/job-1', ['status' => 'failed']],
            'adobe-canceled' => ['adobe', 'https://firefly-api.adobe.io/v3/status/job-1', ['status' => 'canceled']],
            'blackforestlabs' => ['blackforestlabs', 'https://api.eu1.bfl.ai/v1/get_result?id=1', ['status' => 'Error']],
            'ideogram' => ['ideogram', 'gen-1', ['status' => 'failed', 'failure_reason' => 'moderated']],
            'modelslab' => ['modelslab', 'https://modelslab.com/api/v6/images/fetch/123',
                ['status' => 'error', 'code' => 'provider_error', 'message' => 'Generation refused']],
            'replicate' => ['replicate', 'https://api.replicate.com/v1/predictions/p1', ['status' => 'canceled']],
            'replicate-aborted' => ['replicate', 'https://api.replicate.com/v1/predictions/p1', ['status' => 'aborted']],
        ];
    }


    public static function providers() : array
    {
        return [
            'adobe' => ['adobe', 'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1',
                'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1', ['status' => 'running']],
            'blackforestlabs' => ['blackforestlabs', 'https://api.eu1.bfl.ai/v1/get_result?id=1',
                'https://api.eu1.bfl.ai/v1/get_result?id=1', ['status' => 'Pending']],
            'ideogram' => ['ideogram', 'gen-1', 'https://api.ideogram.ai/v1/generations/gen-1', ['status' => 'pending']],
            'modelslab' => ['modelslab', 'https://modelslab.com/api/v6/images/fetch/123',
                'https://modelslab.com/api/v6/images/fetch/123', ['status' => 'processing']],
            'replicate' => ['replicate', 'https://api.replicate.com/v1/predictions/p1',
                'https://api.replicate.com/v1/predictions/p1', ['status' => 'processing']],
        ];
    }


    #[DataProvider( 'providers' )]
    public function testResume( string $name, string $jobId, string $url, array $pending ) : void
    {
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client'] )->response( $pending );

        $response = $this->provider()->ensure( 'resume' )->resume( $jobId );

        $this->assertFalse( $response->ready() );
        $this->assertSame( $jobId, $response->jobId() );
        $this->assertSame( $pending['status'], $response->meta()['status'] );
        $this->assertGreaterThan( 0, $response->retryAfter() );
        $this->assertSame( $url, (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['adobe'] )]
    #[TestWith( ['blackforestlabs'] )]
    #[TestWith( ['ideogram'] )]
    #[TestWith( ['modelslab'] )]
    #[TestWith( ['replicate'] )]
    public function testResumeEmptyJobId( string $name ) : void
    {
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( '' );
    }


    #[DataProvider( 'failures' )]
    public function testResumeFailed( string $name, string $jobId, array $failure ) : void
    {
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client'] )->response( $failure );

        $this->expectException( FailedException::class );
        $this->provider()->resume( $jobId )->ready();
    }


    #[TestWith( ['adobe', 'https://attacker.example/v3/status/job-1'] )]
    #[TestWith( ['adobe', 'http://firefly-api.adobe.io/v3/status/job-1'] )]
    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/images/job-1'] )]
    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/status/..'] )]
    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/status/%2e%2e'] )]
    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/status/job-1/../../images'] )]
    #[TestWith( ['adobe', 'https://developer.adobe.io/v3/status/job-1'] )]
    #[TestWith( ['blackforestlabs', 'https://attacker.example/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://api.bfl.ai.attacker.example/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://attackerbfl.ai/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'http://api.eu1.bfl.ai/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://api.eu1.bfl.ai:8443/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://user@api.bfl.ai/v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'v1/get_result?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://api.bfl.ai/v1/credits'] )]
    #[TestWith( ['blackforestlabs', 'https://api.bfl.ai/v1/get_result/../credits?id=1'] )]
    #[TestWith( ['blackforestlabs', 'https://docs.bfl.ai/v1/get_result?id=1'] )]
    #[TestWith( ['ideogram', '.'] )]
    #[TestWith( ['ideogram', '..'] )]
    #[TestWith( ['modelslab', 'https://attacker.example/api/v6/images/fetch/123'] )]
    #[TestWith( ['modelslab', 'http://modelslab.com/api/v6/images/fetch/123'] )]
    #[TestWith( ['modelslab', 'api/v6/images/fetch/123'] )]
    #[TestWith( ['modelslab', 'https://modelslab.com/api/v6/images/text2img'] )]
    #[TestWith( ['modelslab', 'https://modelslab.com/api/v6/images/fetch/123/../../text2img'] )]
    #[TestWith( ['modelslab', 'https://status.modelslab.com/api/v6/images/fetch/123'] )]
    #[TestWith( ['replicate', 'https://attacker.example/v1/predictions/p1'] )]
    #[TestWith( ['replicate', 'https://api.replicate.com.attacker.example/v1/predictions/p1'] )]
    #[TestWith( ['replicate', 'https://www.replicate.com/v1/predictions/p1'] )]
    #[TestWith( ['replicate', 'v1/predictions/p1'] )]
    #[TestWith( ['replicate', 'https://api.replicate.com/v1/account'] )]
    #[TestWith( ['replicate', 'https://api.replicate.com/v1/predictions/p1/cancel'] )]
    public function testRejectsForeignStatusUrl( string $name, string $jobId ) : void
    {
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->resume( $jobId );
    }


    public function testResumeHttpBaseUrl() : void
    {
        // a plain HTTP base URL doesn't reject the HTTPS status URLs of the API domain
        $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test', 'url' => 'http://api.bfl.ai'] )
            ->response( ['status' => 'Pending'] );

        $response = $this->provider()->resume( 'https://api.bfl.ai/v1/get_result?id=1' );

        $this->assertFalse( $response->ready() );
    }


    public function testResumeBehindApiGateway() : void
    {
        // an API gateway in front of the provider prefixes the path of its status URLs
        $this->prisma( 'image', 'replicate', ['api_key' => 'test', 'url' => 'https://gateway.example/replicate/'] )
            ->response( ['status' => 'processing'] );

        $response = $this->provider()->resume( 'https://gateway.example/replicate/v1/predictions/p1' );

        $this->assertFalse( $response->ready() );
        $this->assertSame( 'https://gateway.example/replicate/v1/predictions/p1', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1', 'running'] )]
    #[TestWith( ['adobe', 'https://firefly-epo854211.adobe.io/v3/status/urn:ff:jobs:1', 'running'] )]
    #[TestWith( ['blackforestlabs', 'https://api.us1.bfl.ai/v1/get_result?id=1', 'Pending'] )]
    #[TestWith( ['modelslab', 'https://modelslab.com/api/v6/images/fetch/123', 'processing'] )]
    #[TestWith( ['replicate', 'https://api.replicate.com/v1/predictions/p1', 'processing'] )]
    public function testResumeApiHostBehindGateway( string $name, string $jobId, string $status ) : void
    {
        // providers return status URLs of their own API host even if the requests are sent through a gateway
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client', 'url' => 'https://gateway.example/'] )
            ->response( ['status' => $status] );

        $response = $this->provider()->resume( $jobId );

        $this->assertFalse( $response->ready() );
        $this->assertSame( $jobId, (string) $this->requests()[0]->getUri() );
    }


    public function testResumeSettledCost() : void
    {
        // the cost is reported by the submit response and settled by the final result
        $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test'] )
            ->response( ['status' => 'Ready', 'result' => ['sample' => 'https://example.com/a.png'], 'cost' => 3.5] );

        $response = $this->provider()->resume( 'https://api.eu1.bfl.ai/v1/get_result?id=1' );

        $this->assertTrue( $response->ready() );
        $this->assertSame( 3.5, $response->usage()['used'] );
    }


    public function testNotResumable() : void
    {
        $this->assertFalse( $this->prisma( 'image', 'openai', ['api_key' => 'test'] )->provider()->has( 'resume' ) );
    }


    #[TestWith( ['Resume', true] )]
    #[TestWith( ['resume', true] )]
    #[TestWith( ['Image\\Imagine', false] )]
    #[TestWith( ['image\\imagine', false] )]
    #[TestWith( ['', false] )]
    public function testHasMethodName( string $method, bool $expected ) : void
    {
        // shared contracts are found too, but only by the method name
        $this->assertSame( $expected, $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )->provider()->has( $method ) );
    }


    public function testQueuedResponse() : void
    {
        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['id' => 'p1', 'status' => 'starting', 'urls' => ['get' => 'https://api.replicate.com/v1/predictions/p1']] );
        $jobId = (string) $this->provider()->imagine( 'prompt' )->jobId();

        // queued job: create the provider again and resume polling the job
        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )
            ->response( ['status' => 'succeeded', 'output' => ['https://replicate.delivery/a.png']] );

        $image = $this->provider()->resume( $jobId );

        $this->assertTrue( $image->ready() );
        $this->assertSame( 'https://replicate.delivery/a.png', $image->first()?->url() );
    }
}
