<?php

namespace Tests\Schema;

use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class ValidateTest extends TestCase
{
    private function person() : Schema
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        return $schema;
    }


    public function testValid() : void
    {
        $this->assertSame( [], $this->person()->validate( ['name' => 'John', 'age' => 30] ) );
    }


    public function testMissingRequired() : void
    {
        $errors = $this->person()->validate( ['name' => 'John'] );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'age', $errors[0] );
    }


    public function testWrongType() : void
    {
        $errors = $this->person()->validate( ['name' => 'John', 'age' => 'thirty'] );

        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'age', $errors[0] );
        $this->assertStringContainsString( 'integer', $errors[0] );
    }


    public function testAdditionalPropertyRejected() : void
    {
        $errors = $this->person()->validate( ['name' => 'John', 'age' => 30, 'evil' => 'x'] );

        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'evil', $errors[0] );
    }


    public function testTopLevelNotObject() : void
    {
        $errors = $this->person()->validate( 'not-an-object' );

        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'object', $errors[0] );
    }


    public function testEmptyObjectAgainstRequired() : void
    {
        // {} decodes to [] in PHP; must still trigger the missing-required check
        $errors = $this->person()->validate( [] );

        $this->assertCount( 2, $errors );
    }


    public function testNestedAndArray() : void
    {
        $schema = Schema::for( 'order', [
            'items' => Schema::array()->items(
                Schema::object( ['sku' => Schema::string()->required()] )
            )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['items' => [['sku' => 'A'], ['sku' => 'B']]] ) );

        $errors = $schema->validate( ['items' => [['sku' => 'A'], ['qty' => 2]]] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'items.1', $errors[0] );
        $this->assertStringContainsString( 'sku', $errors[0] );
    }


    public function testEnum() : void
    {
        $schema = Schema::for( 'pick', [
            'color' => Schema::string()->enum( ['red', 'green'] )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['color' => 'red'] ) );

        $errors = $schema->validate( ['color' => 'blue'] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'color', $errors[0] );
    }


    public function testNumericBounds() : void
    {
        $schema = Schema::for( 'age', [
            'value' => Schema::integer()->min( 0 )->max( 120 )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['value' => 30] ) );
        $this->assertNotEmpty( $schema->validate( ['value' => -1] ) );
        $this->assertNotEmpty( $schema->validate( ['value' => 200] ) );
    }


    public function testNullable() : void
    {
        $schema = Schema::for( 'x', [
            'note' => Schema::string()->nullable()->required(),
            'name' => Schema::string()->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['note' => null, 'name' => 'a'] ) );

        // "name" is not nullable
        $this->assertNotEmpty( $schema->validate( ['note' => null, 'name' => null] ) );
    }


    public function testAnyOf() : void
    {
        $schema = Schema::for( 'x', [
            'value' => Schema::anyOf( [Schema::string(), Schema::integer()] )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['value' => 'text'] ) );
        $this->assertSame( [], $schema->validate( ['value' => 42] ) );

        $errors = $schema->validate( ['value' => true] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'value', $errors[0] );
    }


    public function testRef() : void
    {
        $schema = Schema::for( 'x', [
            'home' => Schema::ref( 'Address' )->required(),
        ] );
        $schema->def( 'Address', Schema::object( ['city' => Schema::string()->required()] ) );

        $this->assertSame( [], $schema->validate( ['home' => ['city' => 'Berlin']] ) );

        $errors = $schema->validate( ['home' => ['zip' => '10115']] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'home', $errors[0] );
        $this->assertStringContainsString( 'city', $errors[0] );
    }


    public function testOptionalNull() : void
    {
        $schema = Schema::for( 'x', [
            'name' => Schema::string()->required(),
            'note' => Schema::string(),
        ] );

        // a null optional property is treated as "not provided", not rejected
        $this->assertSame( [], $schema->validate( ['name' => 'a', 'note' => null] ) );
        // a null REQUIRED property is still rejected
        $this->assertNotEmpty( $schema->validate( ['name' => null, 'note' => 'x'] ) );
    }


    public function testIntegerAcceptsWholeFloat() : void
    {
        $schema = Schema::for( 'x', ['n' => Schema::integer()->required()] );

        $this->assertSame( [], $schema->validate( ['n' => 5] ) );
        $this->assertSame( [], $schema->validate( ['n' => 5.0] ) );
        $this->assertNotEmpty( $schema->validate( ['n' => 5.5] ) );
        $this->assertNotEmpty( $schema->validate( ['n' => INF] ) );   // non-finite is not an integer
    }


    public function testMultipleOfFloat() : void
    {
        $schema = Schema::for( 'x', ['n' => Schema::number()->multipleOf( 0.1 )->required()] );

        $this->assertSame( [], $schema->validate( ['n' => 0.3] ) );
        $this->assertNotEmpty( $schema->validate( ['n' => 0.35] ) );
    }


    public function testNumberEnumFloat() : void
    {
        $schema = Schema::for( 'x', ['n' => Schema::number()->enum( [1, 2, 3] )->required()] );

        $this->assertSame( [], $schema->validate( ['n' => 1.0] ) );
        $this->assertSame( [], $schema->validate( ['n' => 2] ) );
        $this->assertNotEmpty( $schema->validate( ['n' => 4] ) );
    }


    public function testPattern() : void
    {
        $schema = Schema::for( 'x', [
            'code' => Schema::string()->pattern( '^[A-Z]{3}$' )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['code' => 'ABC'] ) );

        $errors = $schema->validate( ['code' => 'abc'] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'code', $errors[0] );
    }


    public function testPatternUnicode() : void
    {
        // "." must count code points (the "u" flag), not bytes, like the length checks
        $schema = Schema::for( 'x', [
            'code' => Schema::string()->pattern( '^.{3}$' )->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['code' => 'äöü'] ) );   // 3 code points, 6 bytes
        $this->assertNotEmpty( $schema->validate( ['code' => 'ab'] ) );
    }


    public function testInvalidPatternRejected() : void
    {
        $this->expectException( \Aimeos\Prisma\Exceptions\PrismaException::class );

        // an uncompilable regex fails at definition time, not silently at validation
        Schema::string()->pattern( '[' );
    }


    public function testPatternSkippedWhenTooLong() : void
    {
        $schema = Schema::for( 'x', [
            'code' => Schema::string()->max( 3 )->pattern( '^[a-z]+$' )->required(),
        ] );

        // an over-long value reports only the length error; the pattern match is not run on it
        $errors = $schema->validate( ['code' => '12345'] );
        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'at most', $errors[0] );
    }


    public function testPatternNonUtf8() : void
    {
        // non-UTF-8 data must not slip through the "u"-flag match silently (byte-mode fallback)
        $schema = Schema::for( 'x', [
            'code' => Schema::string()->pattern( '^[a-z]+$' )->required(),
        ] );

        $this->assertNotEmpty( $schema->validate( ['code' => "\xFF\xFE"] ) );
    }


    public function testUniqueItems() : void
    {
        $schema = Schema::for( 'x', [
            'tags' => Schema::array()->items( Schema::integer() )->unique()->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['tags' => [1, 2, 3]] ) );

        $errors = $schema->validate( ['tags' => [1, 1, 2]] );
        $this->assertNotEmpty( $errors );
        $this->assertStringContainsString( 'tags', $errors[0] );
    }


    public function testUniqueItemsTypeSensitive() : void
    {
        // type-distinct values are NOT duplicates (1 vs "1", true vs 1) per JSON Schema
        $schema = Schema::for( 'x', [
            'tags' => Schema::array()->unique()->required(),
        ] );

        $this->assertSame( [], $schema->validate( ['tags' => [1, '1']] ) );
        $this->assertSame( [], $schema->validate( ['tags' => [true, 1]] ) );
        $this->assertNotEmpty( $schema->validate( ['tags' => ['a', 'a']] ) );
    }


    public function testNullableAnyOf() : void
    {
        $schema = Schema::fromArray( 'x', [
            'type' => 'object',
            'properties' => ['v' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]],
            'required' => ['v'],
        ] );

        $this->assertSame( [], $schema->validate( ['v' => null] ) );   // null allowed by the null branch
        $this->assertSame( [], $schema->validate( ['v' => 'hi'] ) );   // string allowed
        $this->assertNotEmpty( $schema->validate( ['v' => 5] ) );      // matches neither branch
    }
}
