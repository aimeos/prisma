<?php

namespace Tests\Schema;

use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Schema\Types\StringType;
use Aimeos\Prisma\Schema\Types\IntegerType;
use Aimeos\Prisma\Schema\Types\ObjectType;
use PHPUnit\Framework\TestCase;


class SchemaTest extends TestCase
{
    public function testName() : void
    {
        $schema = Schema::for( 'test-schema' );
        $this->assertEquals( 'test-schema', $schema->name() );
    }


    public function testForWithArray() : void
    {
        $schema = Schema::for( 'test', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer(),
        ] );

        $this->assertEquals( 'test', $schema->name() );
        $arr = $schema->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'name', $arr['properties'] );
        $this->assertArrayHasKey( 'age', $arr['properties'] );
        $this->assertContains( 'name', $arr['required'] );
    }


    public function testStrict() : void
    {
        $schema = Schema::for( 'test' );
        $this->assertFalse( $schema->isStrict() );

        $schema->strict();
        $this->assertTrue( $schema->isStrict() );

        $schema->strict( false );
        $this->assertFalse( $schema->isStrict() );
    }


    public function testType() : void
    {
        $schema = Schema::for( 'test', [
            'field' => Schema::string(),
        ] );

        $this->assertInstanceOf( ObjectType::class, $schema->type() );
    }


    public function testToArray() : void
    {
        $schema = Schema::for( 'test', [
            'title' => Schema::string()->required(),
            'count' => Schema::integer()->required(),
        ] );

        $arr = $schema->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertEquals( 'string', $arr['properties']['title']['type'] );
        $this->assertEquals( 'integer', $arr['properties']['count']['type'] );
        $this->assertEquals( ['title', 'count'], $arr['required'] );
    }


    public function testToString() : void
    {
        $schema = Schema::for( 'test' );
        $json = $schema->toString();
        $this->assertJson( $json );

        $data = json_decode( $json, true );
        $this->assertEquals( 'object', $data['type'] );
    }


    public function testMagicToString() : void
    {
        $schema = Schema::for( 'test' );
        $this->assertEquals( $schema->toString(), (string) $schema );
    }


    public function testFromArray() : void
    {
        $schema = Schema::fromArray( 'imported', [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'limit' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['query'],
        ] );

        $this->assertEquals( 'imported', $schema->name() );
        $arr = $schema->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'query', $arr['properties'] );
        $this->assertContains( 'query', $arr['required'] );
    }


    public function testStaticTypeFactories() : void
    {
        $this->assertInstanceOf( StringType::class, Schema::string() );
        $this->assertInstanceOf( IntegerType::class, Schema::integer() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\NumberType::class, Schema::number() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\BooleanType::class, Schema::boolean() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\ArrayType::class, Schema::array() );
        $this->assertInstanceOf( ObjectType::class, Schema::object() );
    }


    public function testObjectWithProperties() : void
    {
        $obj = Schema::object( [
            'name' => Schema::string()->required(),
        ] );

        $arr = $obj->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'name', $arr['properties'] );
    }
}
