<?php

namespace Tests\Tools\Concurrency;

use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Concurrency\Fork;
use Aimeos\Prisma\Tools\Concurrency\Sequential;
use Aimeos\Prisma\Tools\Step;
use PHPUnit\Framework\TestCase;


class ConcurrencyTest extends TestCase
{
    public function testConcurrencyInterface() : void
    {
        $this->assertInstanceOf( Concurrency::class, new Sequential() );
        $this->assertInstanceOf( Concurrency::class, new Fork() );
    }


    public function testSequential() : void
    {
        $tool = Tools::make( 'add', 'Add numbers', Schema::fromArray( 'add', ['type' => 'object'] ), fn( $args ) => (string) ( $args['a'] + $args['b'] ) );

        $steps = [
            new Step( '1', 'add', ['a' => 1, 'b' => 2], $tool ),
            new Step( '2', 'add', ['a' => 3, 'b' => 4], $tool ),
        ];

        $runner = new Sequential();
        $results = $runner->run( $steps );

        $this->assertCount( 2, $results );
        $this->assertInstanceOf( Step::class, $results[0] );
        $this->assertEquals( '1', $results[0]->id() );
        $this->assertEquals( '3', $results[0]->result() );
        $this->assertEquals( '2', $results[1]->id() );
        $this->assertEquals( '7', $results[1]->result() );
    }


    public function testSequentialEmpty() : void
    {
        $runner = new Sequential();
        $results = $runner->run( [] );

        $this->assertCount( 0, $results );
    }


    public function testSequentialErrorHandling() : void
    {
        $tool = Tools::make( 'fail', 'Fails', Schema::fromArray( 'fail', ['type' => 'object'] ), fn() => throw new \RuntimeException( 'boom' ) );

        $steps = [
            new Step( '1', 'fail', [], $tool ),
        ];

        $runner = new Sequential();
        $results = $runner->run( $steps );

        $this->assertCount( 1, $results );
        $this->assertEquals( 'Error: boom', $results[0]->result() );
    }


    public function testForkSingleTaskFallsBack() : void
    {
        $tool = Tools::make( 'echo', 'Echo', Schema::fromArray( 'echo', ['type' => 'object'] ), fn( $args ) => $args['msg'] );

        $steps = [
            new Step( '1', 'echo', ['msg' => 'hello'], $tool ),
        ];

        $runner = new Fork();
        $results = $runner->run( $steps );

        $this->assertCount( 1, $results );
        $this->assertEquals( 'hello', $results[0]->result() );
    }


    public function testForkMultipleTasks() : void
    {
        if( !function_exists( 'pcntl_fork' ) || !function_exists( 'socket_create_pair' ) ) {
            $this->markTestSkipped( 'pcntl and sockets required' );
        }

        $tool = Tools::make( 'double', 'Double', Schema::fromArray( 'double', ['type' => 'object'] ), fn( $args ) => (string) ( $args['n'] * 2 ) );

        $steps = [
            new Step( '1', 'double', ['n' => 5], $tool ),
            new Step( '2', 'double', ['n' => 10], $tool ),
            new Step( '3', 'double', ['n' => 15], $tool ),
        ];

        $runner = new Fork();
        $results = $runner->run( $steps );

        $this->assertCount( 3, $results );
        $this->assertEquals( '10', $results[0]->result() );
        $this->assertEquals( '20', $results[1]->result() );
        $this->assertEquals( '30', $results[2]->result() );
    }


    public function testForkErrorHandling() : void
    {
        if( !function_exists( 'pcntl_fork' ) || !function_exists( 'socket_create_pair' ) ) {
            $this->markTestSkipped( 'pcntl and sockets required' );
        }

        $good = Tools::make( 'ok', 'OK', Schema::fromArray( 'ok', ['type' => 'object'] ), fn() => 'success' );
        $bad = Tools::make( 'fail', 'Fail', Schema::fromArray( 'fail', ['type' => 'object'] ), fn() => throw new \RuntimeException( 'boom' ) );

        $steps = [
            new Step( '1', 'ok', [], $good ),
            new Step( '2', 'fail', [], $bad ),
        ];

        $runner = new Fork();
        $results = $runner->run( $steps );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'success', $results[0]->result() );
        $this->assertEquals( 'Error: boom', $results[1]->result() );
    }
}
