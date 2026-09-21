<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class CancelTest extends TestCase
{
    use MakesPrismaRequests;


    public static function providers() : array
    {
        return [
            'alibaba' => ['alibaba', 'POST', 'https://dashscope-intl.aliyuncs.com/api/v1/tasks/task%2F1/cancel', 200],
            'byteplus' => ['byteplus', 'DELETE', 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks/task%2F1', 200],
            'runway' => ['runway', 'DELETE', 'https://api.dev.runwayml.com/v1/tasks/task%2F1', 204],
        ];
    }


    #[DataProvider( 'providers' )]
    public function testCancel( string $name, string $method, string $url, int $status ) : void
    {
        $this->prisma( 'video', $name, ['api_key' => 'test'] )->response( '', [], $status );

        $this->provider()->ensure( 'cancel' )->cancel( 'task/1' );

        $this->assertSame( $method, $this->requests()[0]->getMethod() );
        $this->assertSame( $url, (string) $this->requests()[0]->getUri() );
    }


    #[TestWith( ['alibaba'] )]
    #[TestWith( ['byteplus'] )]
    #[TestWith( ['runway'] )]
    public function testCancelInvalidJobId( string $name ) : void
    {
        $this->prisma( 'video', $name, ['api_key' => 'test'] );
        $provider = $this->provider();

        // dot segments would escape the cancel endpoint when the URL is resolved
        foreach( ['', '.', '..'] as $jobId )
        {
            try {
                $provider->cancel( $jobId );
                $this->fail( 'BadRequestException expected for "' . $jobId . '"' );
            } catch( BadRequestException $e ) {
                $this->assertNotEmpty( $e->getMessage() );
            }
        }
    }


    public function testCancelFails() : void
    {
        $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( ['code' => 'UnsupportedOperation', 'message' => 'Failed to cancel the task'], [], 400 );

        $this->expectException( BadRequestException::class );
        $this->provider()->cancel( 'task-1' );
    }


    public function testCancelDeleted() : void
    {
        $this->prisma( 'video', 'runway', ['api_key' => 'test'] )->response( ['error' => 'Task not found'], [], 404 );

        $this->expectException( NotFoundException::class );
        $this->provider()->cancel( 'task-1' );
    }


    public function testNotCancelable() : void
    {
        $this->assertFalse( $this->prisma( 'video', 'xai', ['api_key' => 'test'] )->provider()->has( 'cancel' ) );
    }
}
