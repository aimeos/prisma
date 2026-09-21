<?php

namespace Tests\Responses;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
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


    public function testReadyReleasesPollClosure() : void
    {
        $response = FileResponse::fromAsync( function( $response ) {
            $response->add( File::fromBinary( 'data', 'text/plain' ) );
            return true;
        } );

        $this->assertTrue( $response->ready() );

        // a completed response no longer holds the polling closure and can be serialized
        $copy = unserialize( serialize( $response ) );

        $this->assertInstanceOf( FileResponse::class, $copy );
        $this->assertSame( 'data', $copy->binary() );
    }


    public function testReadyStopsPollingFailedJobs() : void
    {
        $polls = 0;
        $exception = new FailedException( 'Job failed' );
        $response = FileResponse::fromAsync( function() use ( &$polls, $exception ) {
            $polls++;
            throw $exception;
        } );

        // failed jobs don't change, so the response throws again without polling the provider
        for( $i = 0; $i < 2; $i++ )
        {
            try {
                $response->ready();
                $this->fail( 'FailedException expected' );
            } catch( FailedException $e ) {
                $this->assertSame( 'Job failed', $e->getMessage() );
            }
        }

        // the failure isn't turned into a successful response without files
        try {
            $response->files();
            $this->fail( 'FailedException expected' );
        } catch( FailedException $e ) {
            $this->assertSame( 1, $polls );
        }
    }


    public function testReadyKeepsPollingExpiredJobs() : void
    {
        $polls = 0;
        $response = FileResponse::fromAsync( function() use ( &$polls ) {
            $polls++;
            throw new NotFoundException( 'Job not found' );
        } );

        // a 404 can also be a job the provider doesn't report yet, so it's polled again
        for( $i = 0; $i < 2; $i++ )
        {
            try {
                $response->ready();
                $this->fail( 'NotFoundException expected' );
            } catch( NotFoundException $e ) {
                $this->assertSame( 'Job not found', $e->getMessage() );
            }
        }

        $this->assertSame( 2, $polls );
    }


    public function testReadyKeepsPollingRateLimitedJobs() : void
    {
        $polls = 0;
        $response = FileResponse::fromAsync( function() use ( &$polls ) {
            $polls++;
            throw new RateLimitException( 'Too many requests' );
        } );

        // rate limits are temporary, so the job is polled again
        for( $i = 0; $i < 2; $i++ )
        {
            try {
                $response->ready();
            } catch( RateLimitException $e ) {
            }
        }

        $this->assertSame( 2, $polls );
    }


    public function testRetryAfterDropsStaleRateLimit() : void
    {
        $polls = 0;
        $response = FileResponse::fromAsync( function() use ( &$polls ) {
            if( ++$polls === 1 ) {
                throw ( new RateLimitException( 'Too many requests' ) )->withRetryAfter( 3600 );
            }
            throw new PrismaException( 'Service unavailable' );
        }, 5 );

        try {
            $response->ready();
        } catch( RateLimitException $e ) {
            $this->assertSame( 3600, $response->retryAfter() );
        }

        // the rate limit window is over after the next poll, so don't keep asking for an hour
        try {
            $response->ready();
        } catch( PrismaException $e ) {
            $this->assertSame( 5, $response->retryAfter() );
        }

        $this->assertSame( 2, $polls );
    }


    public function testRetryAfter() : void
    {
        $response = FileResponse::fromAsync( fn() => true, 7 );

        $this->assertSame( 7, $response->retryAfter() );
        $this->assertTrue( $response->ready() );
        $this->assertSame( 0, $response->retryAfter() );
        $this->assertSame( 0, FileResponse::fromFiles( [] )->retryAfter() );
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
        $this->assertNotEmpty( serialize( $response ) );
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


    public function testWaitRetriesRateLimit() : void
    {
        $polls = 0;
        $response = AsyncFileResponse::fromAsync(
            function( $response ) use ( &$polls ) {
                if( ++$polls === 4 ) {
                    $response->add( File::fromBinary( 'data', 'text/plain' ) );
                    return true;
                }

                throw ( new RateLimitException( 'Too many requests' ) )->withRetryAfter( [12, 2, null][$polls - 1] );
            },
            5
        );

        // rate limited polls wait as long as the provider asks for, but at least the polling interval
        $this->assertInstanceOf( File::class, $response->first() );
        $this->assertSame( 4, $polls );
        $this->assertSame( [12, 5, 5], $response->sleeps );
    }


    public function testWaitRateLimitWithoutRetryAfter() : void
    {
        $polls = 0;
        $response = AsyncFileResponse::fromAsync(
            function( $response ) use ( &$polls ) {
                if( ++$polls === 4 ) {
                    $response->add( File::fromBinary( 'data', 'text/plain' ) );
                    return true;
                }

                throw new RateLimitException( 'Too many requests' );
            },
            5,
            12
        );

        // without Retry-After, the last wait is shortened to the deadline like for other polls
        $this->assertInstanceOf( File::class, $response->first() );
        $this->assertSame( 4, $polls );
        $this->assertSame( [5, 5, 2], $response->sleeps );
    }


    public function testWaitRateLimitBeyondTimeout() : void
    {
        $polls = 0;
        $response = AsyncFileResponse::fromAsync(
            function() use ( &$polls ) {
                $polls++;
                throw ( new RateLimitException( 'Too many requests' ) )->withRetryAfter( 8 );
            },
            5,
            10
        );

        try {
            $response->first();
            $this->fail( 'Expected the rate limit exception' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 8, $e->retryAfter() );
        }

        // the second wait would exceed the deadline, so the rate limit is thrown instead of sleeping
        $this->assertSame( 2, $polls );
        $this->assertSame( [8], $response->sleeps );
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
