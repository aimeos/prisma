<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;


class AlibabaTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['ALIBABA_API_KEY'] ) ) {
            $this->markTestSkipped( 'ALIBABA_API_KEY is not defined in the environment' );
        }
    }


    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/qwen_imagine.png', $response->binary() );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        file_put_contents( __DIR__ . '/results/alibaba_speak.mp3', $response->binary() );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
    }


    public function testVectorize() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
        $image = Image::fromBase64( $base64, 'image/png' );
        $response = Prisma::image()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'vectorize' )
            ->vectorize( [$image], 1024 );

        $this->assertCount( 1, $response->vectors() );
        $this->assertCount( 1024, $response->vectors()[0] );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );
        $schema->type()->withoutAdditionalProperties();

        $response = Prisma::text()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testWrite() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::text()
            ->using( 'alibaba', ['api_key' => $_ENV['ALIBABA_API_KEY']] )
            ->ensure( 'write' )
            ->write( 'What animal is in this image? Reply with just the animal name.', [$image] );

        $this->assertStringContainsStringIgnoringCase( 'cat', $response->text() );
    }
}
