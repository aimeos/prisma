<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class AnthropicTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['ANTHROPIC_API_KEY'] ) ) {
            $this->markTestSkipped( 'ANTHROPIC_API_KEY is not defined in the environment' );
        }
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'anthropic', ['api_key' => $_ENV['ANTHROPIC_API_KEY']] )
            ->model( 'claude-sonnet-4-5-20250929' )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testWrite() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::text()
            ->using( 'anthropic', ['api_key' => $_ENV['ANTHROPIC_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'What animal is in this image? Reply with just the animal name.', [$image] );

        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }
}
