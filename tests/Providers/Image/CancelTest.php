<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class CancelTest extends TestCase
{
    use MakesPrismaRequests;


    public function testAdobe() : void
    {
        $this->prisma( 'image', 'adobe', ['api_key' => 'test', 'client_id' => 'client'] )->response( '' );

        $this->provider()->ensure( 'cancel' )->cancel( 'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1' );

        $this->assertSame( 'PUT', $this->requests()[0]->getMethod() );
        $this->assertSame( 'https://firefly-api.adobe.io/v3/cancel/urn:ff:jobs:1', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['adobe', 'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1?x=1', 'https://firefly-api.adobe.io/v3/cancel/urn:ff:jobs:1'] )]
    #[TestWith( ['replicate', 'https://api.replicate.com/v1/predictions/p1?x=1', 'https://api.replicate.com/v1/predictions/p1/cancel'] )]
    public function testCancelWithoutQuery( string $name, string $jobId, string $url ) : void
    {
        // the cancel endpoint doesn't get the query of the status URL
        $this->prisma( 'image', $name, ['api_key' => 'test', 'client_id' => 'client'] )->response( ['status' => 'canceled'] );

        $this->provider()->cancel( $jobId );

        $this->assertSame( $url, (string) $this->requests()[0]->getUri() );
    }


    public function testAdobeBehindApiGateway() : void
    {
        // the path prefix of an API gateway must survive the rewrite to the cancel endpoint
        $this->prisma( 'image', 'adobe', ['api_key' => 'test', 'client_id' => 'client', 'url' => 'https://gateway.example/adobe/'] )
            ->response( '' );

        $this->provider()->cancel( 'https://gateway.example/adobe/v3/status/urn:ff:jobs:1' );

        $this->assertSame( 'https://gateway.example/adobe/v3/cancel/urn:ff:jobs:1', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['https://attacker.example/v3/status/job-1'] )]
    #[TestWith( ['https://firefly-api.adobe.io/v3/images/job-1'] )]
    #[TestWith( ['https://firefly-api.adobe.io/v3/status/job-1/images'] )]
    #[TestWith( ['job-1'] )]
    public function testAdobeInvalid( string $jobId ) : void
    {
        $this->prisma( 'image', 'adobe', ['api_key' => 'test', 'client_id' => 'client'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->cancel( $jobId );
    }


    #[TestWith( [409, BadRequestException::class] )]
    #[TestWith( [410, NotFoundException::class] )]
    public function testAdobeFinished( int $status, string $exception ) : void
    {
        $this->prisma( 'image', 'adobe', ['api_key' => 'test', 'client_id' => 'client'] )
            ->response( ['error_code' => 'job_finished', 'message' => 'Job already finished'], [], $status );

        $this->expectException( $exception );
        $this->provider()->cancel( 'https://firefly-api.adobe.io/v3/status/urn:ff:jobs:1' );
    }


    public function testReplicate() : void
    {
        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] )->response( ['id' => 'p1', 'status' => 'canceled'] );

        $this->provider()->ensure( 'cancel' )->cancel( 'https://api.replicate.com/v1/predictions/p1' );

        $this->assertSame( 'POST', $this->requests()[0]->getMethod() );
        $this->assertSame( 'https://api.replicate.com/v1/predictions/p1/cancel', (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['https://attacker.example/v1/predictions/p1'] )]
    #[TestWith( ['http://api.replicate.com/v1/predictions/p1'] )]
    #[TestWith( ['v1/predictions/p1'] )]
    #[TestWith( ['https://api.replicate.com/v1/account'] )]
    #[TestWith( ['https://api.replicate.com/v1/predictions/p1/'] )]
    #[TestWith( [''] )]
    public function testReplicateInvalid( string $jobId ) : void
    {
        $this->prisma( 'image', 'replicate', ['api_key' => 'test'] );

        $this->expectException( BadRequestException::class );
        $this->provider()->cancel( $jobId );
    }


    public function testNotCancelable() : void
    {
        $this->assertFalse( $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )->provider()->has( 'cancel' ) );
    }
}
