<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;


class MistralTest extends TestCase
{
    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['MISTRAL_API_KEY'] ) ) {
            $this->markTestSkipped( 'MISTRAL_API_KEY is not defined in the environment' );
        }
   }


    public function testRecognize() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/recognize.png' );
        $response = Prisma::image()
            ->using( 'mistral', ['api_key' => $_ENV['MISTRAL_API_KEY']])
            ->ensure( 'recognize' )
            ->recognize( $image );

        $this->assertEquals( 'This is text', $response->text() );
    }
}
