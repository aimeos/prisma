<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenaiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImage()
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
        $file = $this->prisma( 'image', 'openai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'created' => 1713833628,
                'data' => ['b64_json' => $base64],
                'usage' => [
                    "total_tokens" => 100,
                    "input_tokens" => 50,
                    "output_tokens" => 50,
                    "input_tokens_details" => [
                        "text_tokens" => 10,
                        "image_tokens" => 40
                    ]
                ]
            ] ) )
            ->image( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://api.openai.com/v1/images/generations', (string) $request->getUri() );
        } );

        $this->assertEquals( $base64, $file->base64() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( ['created' => 1713833628], $file->meta() );
        $this->assertEquals( [
            'used' => 100,
            "total_tokens" => 100,
            "input_tokens" => 50,
            "output_tokens" => 50,
            "input_tokens_details" => [
                "text_tokens" => 10,
                "image_tokens" => 40
            ]
        ], $file->usage() );
    }


    public function testInpaint()
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
        $file = $this->prisma( 'image', 'openai', ['api_key' => 'test'] )
            ->response( json_encode( [
                'data' => ['b64_json' => $base64],
            ] ) )
            ->inpaint(
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.openai.com/v1/images/edits', (string) $request->getUri() );
        } );

        $this->assertEquals( $base64, $file->base64() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
