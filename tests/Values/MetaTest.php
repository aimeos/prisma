<?php

namespace Tests\Values;

use Aimeos\Prisma\Values\Meta;
use PHPUnit\Framework\TestCase;


class MetaTest extends TestCase
{
    public function testTypedAccessors() : void
    {
        $meta = new Meta( [
            'id' => 'msg_1',
            'model' => 'gpt-4o',
            'thinking' => 'Let me reason about this.',
            'reasoning_details' => [['type' => 'reasoning.encrypted', 'data' => 'abc']],
        ] );

        $this->assertSame( 'msg_1', $meta->id() );
        $this->assertSame( 'gpt-4o', $meta->model() );
        $this->assertSame( 'Let me reason about this.', $meta->thinking() );
        $this->assertSame( [['type' => 'reasoning.encrypted', 'data' => 'abc']], $meta->reasoningDetails() );
    }


    public function testMissingFieldsReturnNull() : void
    {
        $meta = new Meta( ['created' => 123] );

        $this->assertNull( $meta->id() );
        $this->assertNull( $meta->model() );
        $this->assertNull( $meta->thinking() );
        $this->assertNull( $meta->reasoningDetails() );
    }


    public function testArrayAccessStaysBackwardCompatible() : void
    {
        $meta = new Meta( ['id' => 'msg_1', 'created' => 123] );

        $this->assertSame( 'msg_1', $meta['id'] );
        $this->assertSame( 123, $meta['created'] );
        $this->assertTrue( isset( $meta['id'] ) );
        $this->assertFalse( isset( $meta['missing'] ) );
    }


    public function testCountableIterableAndSerializable() : void
    {
        $data = ['id' => 'msg_1', 'model' => 'gpt-4o'];
        $meta = new Meta( $data );

        $this->assertCount( 2, $meta );
        $this->assertSame( $data, iterator_to_array( $meta ) );
        $this->assertSame( $data, $meta->all() );
        $this->assertSame( $data, $meta->jsonSerialize() );
    }
}
