<?php

namespace Tests\Providers;

use Aimeos\Prisma\Providers\Fake;
use Aimeos\Prisma\Providers\Image\Gemini;
use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\TestCase;


class FakeTest extends TestCase
{
    public function testAssertCalledMatchesArguments() : void
    {
        $fake = new Fake( ['ok'] );
        $fake->use( new Gemini( ['api_key' => 'test'] ) );

        $fake->imagine( 'a cat' );

        // passes for a matching argument matcher, throws for a non-matching one
        $fake->assertCalled( 'imagine', fn( $args ) => $args[0] === 'a cat' );

        $this->expectException( PrismaException::class );
        $fake->assertCalled( 'imagine', fn( $args ) => $args[0] === 'a dog' );
    }


    public function testAssertCalledThrowsWhenNotCalled() : void
    {
        $fake = new Fake( ['ok'] );
        $fake->use( new Gemini( ['api_key' => 'test'] ) );

        $this->expectException( PrismaException::class );
        $fake->assertCalled( 'imagine' );
    }


    public function testCallRecordsInvocations() : void
    {
        $fake = new Fake( ['a', 'b'] );
        $fake->use( new Gemini( ['api_key' => 'test'] ) );

        $fake->imagine();
        $fake->imagine();

        $this->assertTrue( $fake->called( 'imagine' ) );
        $this->assertFalse( $fake->called( 'speak' ) );
        $this->assertCount( 2, $fake->calls() );
        $this->assertEquals( 'imagine', $fake->calls()[0]['method'] );
    }


    public function testCallThrowsQueuedThrowable() : void
    {
        // a queued Throwable simulates a provider error for that call
        $fake = new Fake( [new \RuntimeException( 'boom' )] );
        $fake->use( new Gemini( ['api_key' => 'test'] ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'boom' );
        $fake->imagine();
    }


    public function testCallReturnsResponsesInOrder() : void
    {
        $responses = ['first', 'second', 'third'];

        $fake = new Fake($responses);
        $fake->use( new \Aimeos\Prisma\Providers\Image\Gemini( ['api_key' => 'test'] ) );

        $this->assertEquals('first', $fake->imagine());
        $this->assertEquals('second', $fake->imagine());
        $this->assertEquals('third', $fake->imagine());
    }

    public function testCallThrowsExceptionWhenMethodNotExists() : void
    {
        $provider = $this->createStub(Provider::class);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->expectException(NotImplementedException::class);
        $fake->nonExistentMethod();
    }


    public function testConstructorSetsResponses() : void
    {
        $responses = ['response1', 'response2'];
        $fake = new Fake($responses);

        $this->assertInstanceOf(Fake::class, $fake);
    }


    public function testEnsureCallsProviderEnsure() : void
    {
        $provider = $this->createMock(Provider::class);
        $provider->expects($this->once())
                 ->method('ensure')
                 ->with('testMethod')
                 ->willReturnSelf();

        $fake = new Fake(['response']);
        $fake->use($provider);

        $result = $fake->ensure('testMethod');
        $this->assertSame($fake, $result);
    }


    public function testEnsureThrowsExceptionWhenNoProvider() : void
    {
        $fake = new Fake(['response']);

        $this->expectException(NotImplementedException::class);
        $fake->ensure('testMethod');
    }


    public function testHasReturnsFalseWhenProviderDoesNotHaveMethod() : void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('has')->with('testMethod')->willReturn(false);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->assertFalse($fake->has('testMethod'));
    }


    public function testHasReturnsTrueWhenProviderHasMethod() : void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('has')
                 ->with('testMethod')
                 ->willReturn(true);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->assertTrue($fake->has('testMethod'));
    }


    public function testUseSetsProvider() : void
    {
        $fake = new Fake(['response']);
        $result = $fake->use($this->createStub(Provider::class));

        $this->assertSame($fake, $result);
    }
}