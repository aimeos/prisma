<?php

namespace Tests\Providers;

use Aimeos\Prisma\Providers\Fake;
use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use PHPUnit\Framework\TestCase;


class FakeTest extends TestCase
{
    public function testConstructorSetsResponses()
    {
        $responses = ['response1', 'response2'];
        $fake = new Fake($responses);

        $this->assertInstanceOf(Fake::class, $fake);
    }

    public function testCallReturnsResponsesInOrder()
    {
        $responses = ['first', 'second', 'third'];

        $fake = new Fake($responses);
        $fake->use( new \Aimeos\Prisma\Providers\Image\Gemini( ['api_key' => 'test'] ) );

        $this->assertEquals('first', $fake->imagine());
        $this->assertEquals('second', $fake->imagine());
        $this->assertEquals('third', $fake->imagine());
    }

    public function testCallThrowsExceptionWhenMethodNotExists()
    {
        $provider = $this->createMock(Provider::class);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->expectException(NotImplementedException::class);
        $fake->nonExistentMethod();
    }

    public function testEnsureCallsProviderEnsure()
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

    public function testEnsureThrowsExceptionWhenNoProvider()
    {
        $fake = new Fake(['response']);

        $this->expectException(NotImplementedException::class);
        $fake->ensure('testMethod');
    }

    public function testHasReturnsTrueWhenProviderHasMethod()
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('has')
                 ->with('testMethod')
                 ->willReturn(true);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->assertTrue($fake->has('testMethod'));
    }


    public function testHasReturnsFalseWhenProviderDoesNotHaveMethod()
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('has')->with('testMethod')->willReturn(false);

        $fake = new Fake(['response']);
        $fake->use($provider);

        $this->assertFalse($fake->has('testMethod'));
    }


    public function testUseSetsProvider()
    {
        $fake = new Fake(['response']);
        $result = $fake->use($this->createMock(Provider::class));

        $this->assertSame($fake, $result);
    }
}