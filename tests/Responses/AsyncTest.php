<?php

namespace Tests\Responses;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\FileResponse;
use PHPUnit\Framework\TestCase;


class AsyncTest extends TestCase
{
    public function testReadyPollsOnce() : void
    {
        $polls = 0;
        $response = FileResponse::fromAsync( function() use ( &$polls ) {
            $polls++;
            return false;
        } );

        // ready() performs a single non-blocking poll and never sleeps
        $this->assertFalse( $response->ready() );
        $this->assertEquals( 1, $polls );
    }


    public function testWaitPollsWithOverriddenSleep() : void
    {
        $polls = 0;

        $response = AsyncFileResponse::fromAsync(
            function( $response ) use ( &$polls ) {
                if( ++$polls < 3 ) {
                    return false;
                }

                $response->add( File::fromBinary( 'data', 'text/plain' ) );
                return true;
            },
            5
        );

        $file = $response->first();

        $this->assertEquals( 3, $polls );
        $this->assertSame( [5, 5], $response->sleeps );
        $this->assertInstanceOf( File::class, $file );
    }


    public function testWaitStopsAtTimeout() : void
    {
        $polls = 0;
        $response = AsyncFileResponse::fromAsync(
            function() use ( &$polls ) {
                $polls++;
                return false;
            },
            5,
            10
        );

        try {
            $response->first();
            $this->fail( 'Expected the asynchronous operation to time out' );
        } catch( PrismaException $e ) {
            $this->assertSame( 'Asynchronous operation timed out after 10 seconds', $e->getMessage() );
        }

        $this->assertSame( 3, $polls );
        $this->assertSame( [5, 5], $response->sleeps );
    }
}


class AsyncFileResponse extends FileResponse
{
    /** @var list<int> */
    public array $sleeps = [];


    protected function sleepAsync( int $seconds ) : void
    {
        $this->sleeps[] = $seconds;
    }
}
