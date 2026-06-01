<?php

namespace Tests\Tools\Concurrency;

use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Tools;
use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Concurrency\Sequential;
use Aimeos\Prisma\Tools\Step;
use PHPUnit\Framework\TestCase;


class ConcurrencyTest extends TestCase
{
    public function testConcurrencyInterface() : void
    {
        $this->assertInstanceOf( Concurrency::class, new Sequential() );
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
}
