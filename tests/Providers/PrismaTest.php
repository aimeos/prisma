<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Providers\Fake;
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
