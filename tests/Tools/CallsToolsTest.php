<?php

namespace Tests\Tools;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\HasTools;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Concurrency\Sequential;
use PHPUnit\Framework\TestCase;


class CallsToolsTest extends TestCase
{
    public function testCallsDecrementsOnFailure() : void
    {
        $tool = Tools::make( 'fail', 'desc', Schema::fromArray( 'fail', ['type' => 'object'] ), fn() => throw new \RuntimeException( 'boom' ) )->max( 2 );
        $harness = $this->harness( new Sequential() );
        $harness->withTools( [$tool] );

        $calls = [];

        // Every executed call consumes the budget regardless of the outcome.
        $first = $harness->exec( $this->calls( 'fail' ), $calls );
        $this->assertEquals( 'Error: boom', $first[0]->result() );
        $this->assertEquals( 1, $calls['fail'] );

        $second = $harness->exec( $this->calls( 'fail' ), $calls );
        $this->assertEquals( 'Error: boom', $second[0]->result() );
        $this->assertEquals( 0, $calls['fail'] );

        // The budget is now exhausted, so the tool is no longer executed.
        $third = $harness->exec( $this->calls( 'fail' ), $calls );
        $this->assertStringContainsString( 'exhausted', $third[0]->result() );
        $this->assertEquals( 0, $calls['fail'] );
    }


    public function testCallsDecrementsOnSuccess() : void
    {
        $tool = Tools::make( 'echo', 'desc', Schema::fromArray( 'echo', ['type' => 'object'] ), fn() => 'ok' )->max( 2 );
        $harness = $this->harness( new Sequential() );
        $harness->withTools( [$tool] );

        $calls = [];

        $first = $harness->exec( $this->calls( 'echo' ), $calls );
        $this->assertEquals( 'ok', $first[0]->result() );
        $this->assertEquals( 1, $calls['echo'] );

        $second = $harness->exec( $this->calls( 'echo' ), $calls );
        $this->assertEquals( 'ok', $second[0]->result() );
        $this->assertEquals( 0, $calls['echo'] );

        $third = $harness->exec( $this->calls( 'echo' ), $calls );
        $this->assertStringContainsString( 'exhausted', $third[0]->result() );
        $this->assertEquals( 0, $calls['echo'] );
    }


    public function testCallsGatesWithinBatch() : void
    {
        $tool = Tools::make( 'echo', 'desc', Schema::fromArray( 'echo', ['type' => 'object'] ), fn() => 'ok' )->max( 1 );
        $harness = $this->harness( new Sequential() );
        $harness->withTools( [$tool] );

        $calls = [];
        $results = $harness->exec( $this->calls( 'echo', 2 ), $calls );

        $this->assertEquals( 'ok', $results[0]->result() );
        $this->assertStringContainsString( 'exhausted', $results[1]->result() );
        $this->assertEquals( 0, $calls['echo'] );
    }


    public function testCallsResetsPerRequest() : void
    {
        $tool = Tools::make( 'echo', 'desc', Schema::fromArray( 'echo', ['type' => 'object'] ), fn() => 'ok' )->max( 1 );
        $harness = $this->harness( new Sequential() );
        $harness->withTools( [$tool] );

        $callsA = [];
        $harness->exec( $this->calls( 'echo' ), $callsA );
        $exhausted = $harness->exec( $this->calls( 'echo' ), $callsA );
        $this->assertStringContainsString( 'exhausted', $exhausted[0]->result() );

        // A fresh budget (a new request) starts with the full budget again.
        $callsB = [];
        $fresh = $harness->exec( $this->calls( 'echo' ), $callsB );
        $this->assertEquals( 'ok', $fresh[0]->result() );
        $this->assertEquals( 0, $callsB['echo'] );
    }


    public function testExecutorReceivesAllRunnableSteps() : void
    {
        $seq = Tools::make( 'seq', 'desc', Schema::fromArray( 'seq', ['type' => 'object'] ), fn() => 'S' );
        $con = Tools::make( 'con', 'desc', Schema::fromArray( 'con', ['type' => 'object'] ), fn() => 'C' )->concurrent();

        // A single recording executor injected via withConcurrency() now sees every runnable
        // step - both the sequential and the concurrent one - in the model's call order.
        $recorder = new class implements \Aimeos\Prisma\Tools\Concurrency\Concurrency {
            /** @var array<int, string> */
            public array $seen = [];

            /**
             * @param array<int, \Aimeos\Prisma\Tools\Step> $steps
             * @return array<int, \Aimeos\Prisma\Tools\Step>
             */
            public function run( array $steps ) : array
            {
                foreach( $steps as $step )
                {
                    $this->seen[] = $step->name();

                    if( $tool = $step->tool() ) {
                        $step->complete( ( $tool )( $step->arguments() ) );
                    }
                }

                return $steps;
            }
        };

        $harness = $this->harness( $recorder );
        $harness->withTools( [$seq, $con] );

        $calls = [];
        $results = $harness->exec( [
            ['id' => '1', 'name' => 'seq', 'arguments' => []],
            ['id' => '2', 'name' => 'con', 'arguments' => []],
        ], $calls );

        $this->assertSame( ['seq', 'con'], $recorder->seen );
        $this->assertEquals( 'S', $results[0]->result() );
        $this->assertEquals( 'C', $results[1]->result() );
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function calls( string $name, int $count = 1 ) : array
    {
        $calls = [];

        for( $i = 0; $i < $count; $i++ ) {
            $calls[] = ['id' => (string) $i, 'name' => $name, 'arguments' => []];
        }

        return $calls;
    }


    /**
     * Returns a harness exposing execTools() with the given concurrency strategy.
     */
    private function harness( object $concurrency ) : object
    {
        $obj = new class {
            use CallsTools;
            use HasTools;

            /**
             * @param array<int, array<string, mixed>> $toolCalls
             * @param array<string, int> $calls
             * @return array<int, \Aimeos\Prisma\Tools\Step>
             */
            public function exec( array $toolCalls, array &$calls ) : array
            {
                return $this->execTools( $toolCalls, $calls );
            }
        };

        return $obj->withConcurrency( $concurrency );
    }
}
