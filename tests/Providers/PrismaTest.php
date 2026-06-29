<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Providers\Fake;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Values\Meta;
use Aimeos\Prisma\Values\Observation;
use Aimeos\Prisma\Values\Usage;
use PHPUnit\Framework\TestCase;


class PrismaTest extends TestCase
{
    protected function tearDown() : void
    {
        // Clear the process-global fake so it cannot leak into other tests.
        Prisma::reset();
    }


    public function testFakeRecordsCalls() : void
    {
        $fake = Prisma::fake( ['result'] );

        $output = Prisma::text()->using( 'openai', ['api_key' => 'test'] )->write( 'prompt' );

        $this->assertEquals( 'result', $output );
        $this->assertTrue( $fake->called( 'write' ) );
        $fake->assertCalled( 'write', fn( $args ) => $args[0] === 'prompt' );
    }


    public function testFakeReturnsRecorder() : void
    {
        $fake = Prisma::fake( ['hello'] );

        $this->assertInstanceOf( Fake::class, $fake );

        // a faked provider returns the queued response instead of reaching the API
        $provider = Prisma::text()->using( 'openai', ['api_key' => 'test'] );
        $this->assertInstanceOf( Fake::class, $provider );
        $this->assertEquals( 'hello', $provider->write( 'hi' ) );
    }


    public function testObserveRecordsProviderOperation() : void
    {
        $records = [];
        $fake = Prisma::fake( [
            TextResponse::fake( 'hello' )
                ->withUsage( 8, ['total_tokens' => 8] )
                ->withMeta( ['id' => 'resp_123', 'model' => 'gpt-test'] ),
        ] );

        $response = Prisma::text()
            ->observe( function( Observation $observation ) use ( &$records ) {
                $records[] = $observation;
            } )
            ->using( 'openai', ['api_key' => 'test'] )
            ->ensure( 'write' )
            ->write( 'prompt' );

        $this->assertInstanceOf( TextResponse::class, $response );
        $this->assertTrue( $fake->called( 'write' ) );
        $this->assertCount( 1, $records );
        $this->assertSame( 'write', $records[0]->operation );
        $this->assertSame( 'text', $records[0]->type );
        $this->assertSame( 'openai', $records[0]->provider );
        $this->assertSame( 'gpt-test', $records[0]->model );
        $this->assertNull( $records[0]->error );
        $this->assertInstanceOf( Usage::class, $records[0]->usage );
        $this->assertInstanceOf( Meta::class, $records[0]->meta );
        $this->assertSame( ['used' => 8.0, 'total_tokens' => 8], $records[0]->usage->all() );
        $this->assertSame( ['id' => 'resp_123', 'model' => 'gpt-test'], $records[0]->meta->all() );
        $this->assertGreaterThanOrEqual( 0, $records[0]->durationMs );
    }


    public function testObserveRecordsProviderOperationFailure() : void
    {
        $records = [];
        Prisma::fake( [new \RuntimeException( 'Provider failed' )] );

        try {
            Prisma::text()
                ->observe( function( Observation $observation ) use ( &$records ) {
                    $records[] = $observation;
                } )
                ->using( 'openai', ['api_key' => 'test'] )
                ->write( 'prompt' );

            $this->fail( 'The provider exception was not thrown' );
        } catch( \RuntimeException $e ) {
            $this->assertSame( 'Provider failed', $e->getMessage() );
        }

        $this->assertCount( 1, $records );
        $this->assertSame( 'write', $records[0]->operation );
        $this->assertInstanceOf( \RuntimeException::class, $records[0]->error );
        $this->assertSame( 'Provider failed', $records[0]->error->getMessage() );
        $this->assertSame( 'Provider failed', $records[0]->toArray()['error'] );
        $this->assertNull( $records[0]->usage );
        $this->assertNull( $records[0]->meta );
        $this->assertNull( $records[0]->toArray()['usage'] );
        $this->assertNull( $records[0]->toArray()['meta'] );
    }


    public function testObserveAllowsMissingResponseMetaAndUsage() : void
    {
        $records = [];
        $result = new class {
        };

        Prisma::fake( [$result] );

        $response = Prisma::text()
            ->observe( function( Observation $observation ) use ( &$records ) {
                $records[] = $observation;
            } )
            ->using( 'openai', ['api_key' => 'test'] )
            ->ensure( 'write' )
            ->write( 'prompt' );

        $this->assertSame( $result, $response );
        $this->assertCount( 1, $records );
        $this->assertSame( 'write', $records[0]->operation );
        $this->assertNull( $records[0]->usage );
        $this->assertNull( $records[0]->meta );
        $this->assertNull( $records[0]->toArray()['usage'] );
        $this->assertNull( $records[0]->toArray()['meta'] );
    }


    public function testObserveIsInstanceScoped() : void
    {
        $records = [];
        Prisma::fake( [
            TextResponse::fake( 'observed' ),
            TextResponse::fake( 'plain' ),
            TextResponse::fake( 'unobserved' ),
        ] );

        $prisma = Prisma::text();

        $prisma
            ->observe( function( Observation $observation ) use ( &$records ) {
                $records[] = $observation;
            } )
            ->using( 'openai', ['api_key' => 'test'] )
            ->write( 'observed' );

        $prisma
            ->using( 'openai', ['api_key' => 'test'] )
            ->write( 'plain' );

        Prisma::text()
            ->using( 'openai', ['api_key' => 'test'] )
            ->write( 'plain' );

        $this->assertCount( 2, $records );
        $this->assertSame( 'write', $records[0]->operation );
        $this->assertSame( 'write', $records[1]->operation );
    }


    public function testObserverPreservesFluentProviderConfiguration() : void
    {
        $records = [];
        Prisma::fake( [TextResponse::fake( 'hello' )] );

        Prisma::text()
            ->observe( function( Observation $observation ) use ( &$records ) {
                $records[] = $observation;
            } )
            ->using( 'openai', ['api_key' => 'test'] )
            ->model( 'gpt-custom' )
            ->withMessages( [['role' => 'user', 'content' => 'previous']] )
            ->withMaxResponseSize( 1024 )
            ->withMaxTokens( 32 )
            ->withToolApproval( fn() => true )
            ->write( 'prompt' );

        $this->assertCount( 1, $records );
        $this->assertSame( 'write', $records[0]->operation );
        $this->assertSame( 'gpt-custom', $records[0]->model );
    }


    public function testObserverRecordsStreamWhenDrained() : void
    {
        $records = [];
        Prisma::fake( [
            TextResponse::fromStream( function( TextResponse $response ) {
                $response
                    ->withUsage( 4, ['total_tokens' => 4] )
                    ->withMeta( ['id' => 'resp_stream', 'model' => 'gpt-stream'] );

                yield 'hello';
            } ),
        ] );

        $response = Prisma::text()
            ->observe( function( Observation $observation ) use ( &$records ) {
                $records[] = $observation;
            } )
            ->using( 'openai', ['api_key' => 'test'] )
            ->ensure( 'stream' )
            ->stream( 'prompt' );

        $this->assertSame( [], $records );
        $this->assertSame( ['hello'], iterator_to_array( $response->stream() ) );

        $this->assertCount( 1, $records );
        $this->assertSame( 'stream', $records[0]->operation );
        $this->assertSame( 'gpt-stream', $records[0]->model );
        $this->assertNull( $records[0]->error );
        $this->assertInstanceOf( Usage::class, $records[0]->usage );
        $this->assertInstanceOf( Meta::class, $records[0]->meta );
        $this->assertSame( ['used' => 4.0, 'total_tokens' => 4], $records[0]->usage->all() );
        $this->assertSame( ['id' => 'resp_stream', 'model' => 'gpt-stream'], $records[0]->meta->all() );
    }


    public function testObserverExceptionsDoNotBreakProviderOperation() : void
    {
        Prisma::fake( [TextResponse::fake( 'hello' )] );
        $log = tempnam( sys_get_temp_dir(), 'prisma-observer-' );
        $previous = ini_get( 'error_log' );

        if( $log !== false ) {
            ini_set( 'error_log', $log );
        }

        try {
            $response = Prisma::text()
                ->observe( function() {
                    throw new \RuntimeException( 'Observer failed' );
                } )
                ->using( 'openai', ['api_key' => 'test'] )
                ->write( 'prompt' );

            $this->assertSame( 'hello', $response->text() );
        } finally {
            ini_set( 'error_log', $previous === false ? '' : $previous );

            if( $log !== false ) {
                @unlink( $log );
            }
        }
    }


    public function testResetClearsFake() : void
    {
        Prisma::fake( ['x'] );
        Prisma::reset();

        // with the fake cleared, using() returns the real provider again
        $provider = Prisma::text()->using( 'openai', ['api_key' => 'test'] );
        $this->assertNotInstanceOf( Fake::class, $provider );
    }


    public function testUsingRejectsNamespaceEscapeInName() : void
    {
        // a backslash in the provider name must be rejected before it reaches the class name, so it
        // cannot escape the Providers\{Type} namespace or trigger the autoloader
        $this->expectException( NotImplementedException::class );

        Prisma::text()->using( 'Sub\\Evil' );
    }


    public function testUsingRejectsInvalidType() : void
    {
        // the media type is interpolated into the class name too, so it is validated as well
        $this->expectException( NotImplementedException::class );

        Prisma::type( 'text\\Evil' )->using( 'openai' );
    }
}
