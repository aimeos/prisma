<?php

namespace Tests\Schema\Types;

use Aimeos\Prisma\Schema\Types\Type;
use Aimeos\Prisma\Schema\Types\StringType;
use Aimeos\Prisma\Schema\Types\IntegerType;
use Aimeos\Prisma\Schema\Types\NumberType;
use Aimeos\Prisma\Schema\Types\BooleanType;
use Aimeos\Prisma\Schema\Types\ArrayType;
use Aimeos\Prisma\Schema\Types\ObjectType;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class TypeTest extends TestCase
{
    public function testAnyOfAsObjectProperty() : void
    {
        $type = Type::fromArray( [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
                    ],
                ],
            ],
            'required' => ['value'],
        ] );

        $arr = $type->toArray();
        $this->assertArrayHasKey( 'anyOf', $arr['properties']['value'] );
        $this->assertContains( 'value', $arr['required'] );
        $this->assertEquals( 'object', $arr['properties']['value']['anyOf'][1]['type'] );
    }


    // --- AnyOfType ---

    public function testAnyOfBasic() : void
    {
        $type = new \Aimeos\Prisma\Schema\Types\AnyOfType( [
            new StringType(),
            new IntegerType(),
        ] );

        $arr = $type->toArray();
        $this->assertArrayNotHasKey( 'type', $arr );
        $this->assertCount( 2, $arr['anyOf'] );
        $this->assertEquals( 'string', $arr['anyOf'][0]['type'] );
        $this->assertEquals( 'integer', $arr['anyOf'][1]['type'] );
    }


    public function testAnyOfFromArray() : void
    {
        $type = Type::fromArray( [
            'description' => 'string or number',
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'number'],
            ],
        ] );

        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\AnyOfType::class, $type );
        $arr = $type->toArray();
        $this->assertEquals( 'string or number', $arr['description'] );
        $this->assertEquals( 'string', $arr['anyOf'][0]['type'] );
        $this->assertEquals( 'number', $arr['anyOf'][1]['type'] );
    }


    public function testAnyOfWithDescriptionAndAdd() : void
    {
        $type = ( new \Aimeos\Prisma\Schema\Types\AnyOfType( [new StringType()] ) )
            ->description( 'A value or an object' )
            ->add( new ObjectType( ['id' => new IntegerType()] ) );

        $arr = $type->toArray();
        $this->assertEquals( 'A value or an object', $arr['description'] );
        $this->assertCount( 2, $arr['anyOf'] );
        $this->assertEquals( 'object', $arr['anyOf'][1]['type'] );
    }


    // --- ArrayType ---

    public function testArrayBasic() : void
    {
        $type = new ArrayType();
        $this->assertEquals( 'array', $type->toArray()['type'] );
    }


    public function testArrayFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'array',
            'minItems' => 2,
            'maxItems' => 5,
            'uniqueItems' => true,
            'items' => ['type' => 'integer'],
            'default' => [1, 2],
        ] );

        $this->assertInstanceOf( ArrayType::class, $type );
        $arr = $type->toArray();
        $this->assertEquals( 2, $arr['minItems'] );
        $this->assertEquals( 5, $arr['maxItems'] );
        $this->assertTrue( $arr['uniqueItems'] );
        $this->assertEquals( 'integer', $arr['items']['type'] );
    }


    public function testArrayWithConstraints() : void
    {
        $type = ( new ArrayType() )
            ->min( 1 )
            ->max( 10 )
            ->unique()
            ->items( new StringType() )
            ->default( ['a', 'b'] );

        $arr = $type->toArray();
        $this->assertEquals( 1, $arr['minItems'] );
        $this->assertEquals( 10, $arr['maxItems'] );
        $this->assertTrue( $arr['uniqueItems'] );
        $this->assertEquals( 'string', $arr['items']['type'] );
        $this->assertEquals( ['a', 'b'], $arr['default'] );
    }


    // --- BooleanType ---

    public function testBooleanBasic() : void
    {
        $type = new BooleanType();
        $this->assertEquals( 'boolean', $type->toArray()['type'] );
    }


    public function testBooleanDefault() : void
    {
        $type = ( new BooleanType() )->default( true );
        $this->assertTrue( $type->toArray()['default'] );
    }


    public function testBooleanFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'boolean',
            'default' => false,
        ] );

        $this->assertInstanceOf( BooleanType::class, $type );
        $this->assertFalse( $type->toArray()['default'] );
    }


    public function testDescription() : void
    {
        $type = ( new IntegerType() )->description( 'A count' );
        $this->assertEquals( 'A count', $type->toArray()['description'] );
    }


    public function testEnum() : void
    {
        $type = ( new StringType() )->enum( ['red', 'green', 'blue'] );
        $this->assertEquals( ['red', 'green', 'blue'], $type->toArray()['enum'] );
    }


    public function testEnumWithInvalidClass() : void
    {
        $this->expectException( \InvalidArgumentException::class );
        ( new StringType() )->enum( \stdClass::class );
    }


    public function testFromArrayDefaultsToString() : void
    {
        $type = Type::fromArray( ['type' => 'unknown'] );
        $this->assertInstanceOf( StringType::class, $type );
    }


    public function testFromArrayNullable() : void
    {
        $type = Type::fromArray( [
            'type' => ['string', 'null'],
            'title' => 'Nullable string',
        ] );

        $arr = $type->toArray();
        $this->assertEquals( ['string', 'null'], $arr['type'] );
        $this->assertEquals( 'Nullable string', $arr['title'] );
    }


    public function testFromArrayWithEnum() : void
    {
        $type = Type::fromArray( [
            'type' => 'string',
            'enum' => ['a', 'b', 'c'],
        ] );

        $this->assertEquals( ['a', 'b', 'c'], $type->toArray()['enum'] );
    }


    // --- IntegerType ---

    public function testIntegerBasic() : void
    {
        $type = new IntegerType();
        $this->assertEquals( 'integer', $type->toArray()['type'] );
    }


    public function testIntegerFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 99,
            'multipleOf' => 3,
            'default' => 9,
        ] );

        $this->assertInstanceOf( IntegerType::class, $type );
        $arr = $type->toArray();
        $this->assertEquals( 1, $arr['minimum'] );
        $this->assertEquals( 99, $arr['maximum'] );
        $this->assertEquals( 3, $arr['multipleOf'] );
        $this->assertEquals( 9, $arr['default'] );
    }


    public function testIntegerWithConstraints() : void
    {
        $type = ( new IntegerType() )
            ->min( 0 )
            ->max( 1000 )
            ->multipleOf( 5 )
            ->default( 42 );

        $arr = $type->toArray();
        $this->assertEquals( 0, $arr['minimum'] );
        $this->assertEquals( 1000, $arr['maximum'] );
        $this->assertEquals( 5, $arr['multipleOf'] );
        $this->assertEquals( 42, $arr['default'] );
    }


    public function testNestedObjectFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'object',
            'properties' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'street' => ['type' => 'string'],
                        'zip' => ['type' => 'string'],
                    ],
                    'required' => ['street'],
                ],
            ],
        ] );

        $arr = $type->toArray();
        $this->assertEquals( 'object', $arr['properties']['address']['type'] );
        $this->assertContains( 'street', $arr['properties']['address']['required'] );
    }


    public function testNonNullableEnumKeepsNoNull() : void
    {
        $type = ( new StringType() )->enum( ['red', 'green', 'blue'] );
        $this->assertEquals( ['red', 'green', 'blue'], $type->toArray()['enum'] );
    }


    public function testNullable() : void
    {
        $type = ( new StringType() )->nullable();
        $arr = $type->toArray();
        $this->assertEquals( ['string', 'null'], $arr['type'] );
    }


    public function testNullableEnumFromArrayIncludesNull() : void
    {
        $type = Type::fromArray( [
            'type' => ['string', 'null'],
            'enum' => ['start', 'center', 'end'],
        ] );
        $arr = $type->toArray();

        $this->assertEquals( ['string', 'null'], $arr['type'] );
        $this->assertEquals( ['start', 'center', 'end', null], $arr['enum'] );
    }


    public function testNullableEnumIncludesNull() : void
    {
        $type = ( new StringType() )->enum( ['start', 'center', 'end'] )->nullable();
        $arr = $type->toArray();

        $this->assertEquals( ['string', 'null'], $arr['type'] );
        $this->assertEquals( ['start', 'center', 'end', null], $arr['enum'] );
    }


    // --- NumberType ---

    public function testNumberBasic() : void
    {
        $type = new NumberType();
        $this->assertEquals( 'number', $type->toArray()['type'] );
    }


    public function testNumberFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'number',
            'minimum' => 0.0,
            'maximum' => 10.5,
            'default' => 5.0,
        ] );

        $this->assertInstanceOf( NumberType::class, $type );
    }


    public function testNumberWithConstraints() : void
    {
        $type = ( new NumberType() )
            ->min( 0.5 )
            ->max( 99.9 )
            ->multipleOf( 0.1 )
            ->default( 1.5 );

        $arr = $type->toArray();
        $this->assertEquals( 0.5, $arr['minimum'] );
        $this->assertEquals( 99.9, $arr['maximum'] );
        $this->assertEquals( 0.1, $arr['multipleOf'] );
        $this->assertEquals( 1.5, $arr['default'] );
    }


    // --- ObjectType ---

    public function testObjectBasic() : void
    {
        $type = new ObjectType();
        $this->assertEquals( 'object', $type->toArray()['type'] );
    }


    public function testObjectFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'label' => ['type' => 'string', 'description' => 'Label text'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ] );

        $this->assertInstanceOf( ObjectType::class, $type );
        $arr = $type->toArray();
        $this->assertArrayHasKey( 'id', $arr['properties'] );
        $this->assertContains( 'id', $arr['required'] );
        $this->assertFalse( $arr['additionalProperties'] );
    }


    public function testObjectWithProperties() : void
    {
        $type = new ObjectType( [
            'name' => ( new StringType() )->required(),
            'age' => new IntegerType(),
        ] );

        $arr = $type->toArray();
        $this->assertEquals( 'object', $arr['type'] );
        $this->assertArrayHasKey( 'name', $arr['properties'] );
        $this->assertArrayHasKey( 'age', $arr['properties'] );
        $this->assertEquals( ['name'], $arr['required'] );
    }


    public function testObjectWithoutAdditionalProperties() : void
    {
        $type = ( new ObjectType() )->withoutAdditionalProperties();
        $arr = $type->toArray();
        $this->assertFalse( $arr['additionalProperties'] );
    }


    // --- Shared Type features ---

    public function testRequired() : void
    {
        $type = ( new StringType() )->required();

        $obj = new ObjectType( ['field' => $type] );
        $arr = $obj->toArray();
        $this->assertEquals( ['field'], $arr['required'] );
    }


    // --- StringType ---

    public function testStringBasic() : void
    {
        $type = new StringType();
        $arr = $type->toArray();
        $this->assertEquals( 'string', $arr['type'] );
    }


    public function testStringFromArray() : void
    {
        $type = Type::fromArray( [
            'type' => 'string',
            'minLength' => 5,
            'maxLength' => 50,
            'pattern' => '\\d+',
            'format' => 'date-time',
            'description' => 'A string field',
            'default' => 'abc',
        ] );

        $this->assertInstanceOf( StringType::class, $type );
        $arr = $type->toArray();
        $this->assertEquals( 'string', $arr['type'] );
        $this->assertEquals( 5, $arr['minLength'] );
        $this->assertEquals( 50, $arr['maxLength'] );
        $this->assertEquals( '\\d+', $arr['pattern'] );
        $this->assertEquals( 'date-time', $arr['format'] );
        $this->assertEquals( 'A string field', $arr['description'] );
        $this->assertEquals( 'abc', $arr['default'] );
    }


    public function testStringWithConstraints() : void
    {
        $type = ( new StringType() )
            ->min( 1 )
            ->max( 100 )
            ->pattern( '^[a-z]+$' )
            ->format( 'email' )
            ->default( 'hello' );

        $arr = $type->toArray();
        $this->assertEquals( 1, $arr['minLength'] );
        $this->assertEquals( 100, $arr['maxLength'] );
        $this->assertEquals( '^[a-z]+$', $arr['pattern'] );
        $this->assertEquals( 'email', $arr['format'] );
        $this->assertEquals( 'hello', $arr['default'] );
    }


    public function testTitle() : void
    {
        $type = ( new StringType() )->title( 'My Title' );
        $this->assertEquals( 'My Title', $type->toArray()['title'] );
    }


    public function testToStringMethod() : void
    {
        $type = new StringType();
        $json = $type->toString();
        $this->assertJson( $json );
        $this->assertEquals( $json, (string) $type );
    }
}
