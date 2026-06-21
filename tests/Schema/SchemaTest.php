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


    public function testFilter() : void
    {
        $schema = Schema::for( 'test', [
            'name' => Schema::string()->required()->title( 'Name' )->min( 1 )->max( 100 )->pattern( '^[a-z]+$' ),
            'age' => Schema::integer()->description( 'User age' )->min( 0 )->max( 150 ),
            'tags' => Schema::array()->items( Schema::string()->format( 'uri' ) ),
            'address' => Schema::object( [
                'city' => Schema::string()->title( 'City' )->required(),
                'zip' => Schema::string()->pattern( '^\d{5}$' ),
            ] ),
        ] );

        $filtered = $schema->filter( ['type', 'description', 'enum', 'properties', 'required', 'items', 'nullable'] );

        $this->assertEquals( 'object', $filtered['type'] );
        $this->assertArrayHasKey( 'properties', $filtered );
        $this->assertContains( 'name', $filtered['required'] );

        // title, minLength, maxLength, pattern should be stripped
        $this->assertArrayNotHasKey( 'title', $filtered['properties']['name'] );
        $this->assertArrayNotHasKey( 'minLength', $filtered['properties']['name'] );
        $this->assertArrayNotHasKey( 'maxLength', $filtered['properties']['name'] );
        $this->assertArrayNotHasKey( 'pattern', $filtered['properties']['name'] );

        // description is allowed
        $this->assertEquals( 'User age', $filtered['properties']['age']['description'] );
        // minimum, maximum should be stripped
        $this->assertArrayNotHasKey( 'minimum', $filtered['properties']['age'] );
        $this->assertArrayNotHasKey( 'maximum', $filtered['properties']['age'] );

        // nested array items filtered
        $this->assertArrayNotHasKey( 'format', $filtered['properties']['tags']['items'] );

        // nested object filtered
        $this->assertArrayNotHasKey( 'title', $filtered['properties']['address']['properties']['city'] );
        $this->assertArrayNotHasKey( 'pattern', $filtered['properties']['address']['properties']['zip'] );
        $this->assertContains( 'city', $filtered['properties']['address']['required'] );
    }


    public function testFilterKeepsAllWhenAllAllowed() : void
    {
        $schema = Schema::for( 'test', [
            'name' => Schema::string()->title( 'Name' )->description( 'A name' ),
        ] );

        $full = $schema->toArray();
        $filtered = $schema->filter( ['type', 'title', 'description', 'properties', 'required'] );

        $this->assertEquals( $full, $filtered );
    }


    public function testStaticTypeFactories() : void
    {
        $this->assertInstanceOf( StringType::class, Schema::string() );
        $this->assertInstanceOf( IntegerType::class, Schema::integer() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\NumberType::class, Schema::number() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\BooleanType::class, Schema::boolean() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\ArrayType::class, Schema::array() );
        $this->assertInstanceOf( ObjectType::class, Schema::object() );
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\AnyOfType::class, Schema::anyOf() );
    }


    public function testAnyOfFactory() : void
    {
        $anyOf = Schema::anyOf( [Schema::string(), Schema::integer()] );

        $arr = $anyOf->toArray();
        $this->assertArrayNotHasKey( 'type', $arr );
        $this->assertEquals( 'string', $arr['anyOf'][0]['type'] );
        $this->assertEquals( 'integer', $arr['anyOf'][1]['type'] );
    }


    public function testFilterRecursesIntoAnyOf() : void
    {
        $schema = Schema::fromArray( 'test', [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'anyOf' => [
                        ['type' => 'string', 'description' => 'keep', 'pattern' => 'drop'],
                        ['type' => 'integer'],
                    ],
                ],
            ],
        ] );

        $filtered = $schema->filter( ['type', 'properties', 'anyOf', 'description'] );
        $branch = $filtered['properties']['value']['anyOf'][0];

        $this->assertEquals( 'keep', $branch['description'] );
        $this->assertArrayNotHasKey( 'pattern', $branch );
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


    public function testRefFactory() : void
    {
        $this->assertInstanceOf( \Aimeos\Prisma\Schema\Types\RefType::class, Schema::ref( 'Address' ) );
    }


    public function testRefResolvesName() : void
    {
        $this->assertEquals( ['$ref' => '#/$defs/Address'], Schema::ref( 'Address' )->toArray() );
    }


    public function testRefKeepsPointer() : void
    {
        $this->assertEquals( ['$ref' => '#/components/Address'], Schema::ref( '#/components/Address' )->toArray() );
    }


    public function testDefAndRef() : void
    {
        $schema = Schema::for( 'person', [
            'address' => Schema::ref( 'Address' )->required(),
        ] )->def( 'Address', Schema::object( [
            'city' => Schema::string()->required(),
        ] ) );

        $arr = $schema->toArray();

        $this->assertEquals( '#/$defs/Address', $arr['properties']['address']['$ref'] );
        $this->assertArrayHasKey( 'Address', $arr['$defs'] );
        $this->assertEquals( 'object', $arr['$defs']['Address']['type'] );
        $this->assertEquals( ['city'], $arr['$defs']['Address']['required'] );
    }


    public function testFromArrayRoundTripsRefAndDefs() : void
    {
        $def = [
            'type' => 'object',
            'properties' => [
                'address' => ['$ref' => '#/$defs/Address'],
            ],
            'required' => ['address'],
            '$defs' => [
                'Address' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ],
            ],
        ];

        $arr = Schema::fromArray( 'person', $def )->toArray();

        $this->assertEquals( $def['properties']['address'], $arr['properties']['address'] );
        $this->assertEquals( $def['$defs'], $arr['$defs'] );
    }


    public function testFilterRecursesIntoDefs() : void
    {
        $schema = Schema::fromArray( 'test', [
            'type' => 'object',
            '$defs' => [
                'Address' => [
                    'type' => 'object',
                    'description' => 'keep',
                    'properties' => [
                        'city' => ['type' => 'string', 'pattern' => 'drop'],
                    ],
                ],
            ],
        ] );

        $filtered = $schema->filter( ['type', 'properties', 'description', '$defs'] );
        $address = $filtered['$defs']['Address'];

        $this->assertEquals( 'keep', $address['description'] );
        $this->assertArrayNotHasKey( 'pattern', $address['properties']['city'] );
    }


    public function testMap() : void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        $result = Schema::map( $schema, function( array $node ) {
            if( ( $node['type'] ?? null ) === 'object' ) {
                $node['additionalProperties'] = false;
            }

            return $node;
        } );

        $this->assertFalse( $result['additionalProperties'] );                                 // root transformed
        $this->assertArrayNotHasKey( 'additionalProperties', $result['properties']['name'] );  // non-object untouched
        $this->assertEquals( 'string', $result['properties']['tags']['items']['type'] );       // recursion reaches nested items
    }
}
