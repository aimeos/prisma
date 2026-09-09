<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class CohereTest extends TestCase
{
    use MakesPrismaRequests;


    public function testVectorize() : void
    {
        $response = $this->prisma( 'image', 'cohere', ['api_key' => 'test'] )
            ->response( '{
                "id": "da6e531f-54c6-4a73-bf92-f60566d8d753",
                "embeddings": {
                    "float": [
                        [0.1, 0.2, 0.3]
                    ]
                },
                "meta": {
                    "api_version": {
                        "version": "2",
                        "is_experimental": true
                    },
                    "billed_units": {
                        "images": 1
                    },
                    "warnings": [
                        "You are using an experimental version"
                    ]
                }
            }' )
            ->ensure( 'vectorize' )
            ->vectorize( [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'https://api.cohere.ai/v2/embed', (string) $request->getUri() );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
        $this->assertSame( 1.0, $response->usage()['used'] );
        $this->assertSame( ['You are using an experimental version'], $response->meta()['warnings'] );
    }


    #[DataProvider('billingCases')]
    public function testSharedEmbeddingResponse( string $type, array $units, float $expected ) : void
    {
        $meta = ['billed_units' => $units, 'warnings' => ['Test warning']];
        $input = $type === 'image' ? Image::fromBinary( 'PNG', 'image/png' ) : 'Hello';
        $response = $this->prisma( $type, 'cohere', ['api_key' => 'test'] )
            ->response( ['meta' => $meta] )->vectorize( [$input] );

        $this->assertSame( [], $response->vectors() );
        $this->assertSame( ['used' => $expected] + $units, $response->usage()->all() );
        $this->assertSame( $meta, $response->meta()->all() );
    }


    public static function billingCases() : array
    {
        return [
            'text tokens' => ['text', ['input_tokens' => '4', 'images' => 2], 4.0],
            'image count' => ['image', ['input_tokens' => 4, 'images' => '2'], 2.0],
            'text missing' => ['text', [], 0.0],
            'image missing' => ['image', [], 0.0],
            'text invalid' => ['text', ['input_tokens' => 'unknown'], 0.0],
            'image invalid' => ['image', ['images' => 'unknown'], 0.0],
        ];
    }
}
