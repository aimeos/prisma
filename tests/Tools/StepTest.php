<?php

namespace Tests\Tools;

use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Concurrency\Sequential;
use Aimeos\Prisma\Tools\Step;
use PHPUnit\Framework\TestCase;


class StepTest extends TestCase
{
    public function testStep() : void
    {
        $step = new Step( 'call_123', 'search', ['query' => 'hello'] );
        $step->complete( 'found it' );

        $this->assertEquals( 'call_123', $step->id() );
        $this->assertEquals( 'search', $step->name() );
        $this->assertEquals( ['query' => 'hello'], $step->arguments() );
        $this->assertEquals( 'found it', $step->result() );
    }


    public function testStepNullId() : void
    {
        $step = new Step( null, 'test', [] );

        $this->assertNull( $step->id() );
    }


    public function testStepTool() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => 'ok' );
        $step = new Step( 'id1', 'test', [], $tool );

        $this->assertSame( $tool, $step->tool() );
    }


    public function testStepToolNull() : void
    {
        $step = new Step( 'id1', 'test', [] );

        $this->assertNull( $step->tool() );
    }


    public function testComplete() : void
    {
        $step = new Step( 'id1', 'test', [] );
        $step->complete( 'result text' );

        $this->assertEquals( 'result text', $step->result() );
    }


    public function testCompleteDefaults() : void
    {
        $step = new Step( 'id1', 'test', [] );

        $this->assertEquals( '', $step->result() );
    }


    public function testStepsOnResponse() : void
    {
        $step1 = new Step( 'id1', 'search', ['q' => 'a'] );
        $step1->complete( 'result1' );
        $step2 = new Step( 'id2', 'fetch', ['id' => 1] );
        $step2->complete( 'result2' );

        $response = TextResponse::fromText( 'hello' )->withSteps( [$step1, $step2] );

        $this->assertCount( 2, $response->steps() );
        $this->assertInstanceOf( Step::class, $response->steps()[0] );
        $this->assertEquals( 'search', $response->steps()[0]->name() );
        $this->assertEquals( 'fetch', $response->steps()[1]->name() );
    }


    public function testRateLimit() : void
    {
        $response = TextResponse::fromText( 'hello' )
            ->withRateLimit( new \Aimeos\Prisma\Values\RateLimit( limit: 100, remaining: 95, reset: '1234567890' ) );

        $this->assertEquals( 100, $response->rateLimit()->limit() );
        $this->assertEquals( 95, $response->rateLimit()->remaining() );
        $this->assertEquals( '1234567890', $response->rateLimit()->reset() );
    }


    public function testRateLimitEmpty() : void
    {
        $response = TextResponse::fromText( 'hello' );

        $this->assertNull( $response->rateLimit() );
    }


    public function testToolReturnsString() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => 'plain string' );

        $steps = [new Step( '1', 'test', [], $tool )];

        $runner = new Sequential();
        $results = $runner->run( $steps );

        $this->assertCount( 1, $results );
        $this->assertEquals( 'plain string', $results[0]->result() );
    }


    public function testToolReturnsArray() : void
    {
        $tool = Tools::make( 'test', 'desc', Schema::fromArray( 'test', ['type' => 'object'] ), fn() => ['key' => 'value'] );

        $steps = [new Step( '1', 'test', [], $tool )];

        $runner = new Sequential();
        $results = $runner->run( $steps );

        $this->assertCount( 1, $results );
        $this->assertEquals( '{"key":"value"}', $results[0]->result() );
    }
}
