<?php

namespace Tests\Responses;

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


    public function testWaitPollsWithInjectedSleep() : void
    {
        $polls = 0;
        $slept = [];

        $response = FileResponse::fromAsync(
            function( $response ) use ( &$polls ) {
                if( ++$polls < 3 ) {
                    return false;
                }

                $response->add( File::fromBinary( 'data', 'text/plain' ) );
                return true;
            },
            5,                                       // a real sleep would block this test for 10s
            function( int $seconds ) use ( &$slept ) {
                $slept[] = $seconds;                 // injected sleep keeps the poll loop instant
            }
        );

        $file = $response->first();

        $this->assertEquals( 3, $polls );
        $this->assertSame( [5, 5], $slept );
        $this->assertInstanceOf( File::class, $file );
    }
}
