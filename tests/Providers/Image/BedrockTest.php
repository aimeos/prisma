<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BedrockTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $file = $this->prisma( 'image', 'bedrock', ['api_key' => 'test'] )
            ->response( '{
                "images": [
                    "' . base64_encode( 'PNG' ) . '"
                ],
                "error": "only on failure"
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-canvas-v1:0/invoke', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testInpaint() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
        $file = $this->prisma( 'image', 'bedrock', ['api_key' => 'test'] )
            ->response( '{
                "images": [
                    "' . base64_encode( 'PNG' ) . '"
                ],
                "error": "only on failure"
            }' )
            ->ensure( 'inpaint' )
            ->inpaint(
                Image::fromBinary( 'PNG', 'image/png' ),
                Image::fromBase64( $base64, 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-canvas-v1:0/invoke', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testIsolate() : void
    {
        $file = $this->prisma( 'image', 'bedrock', ['api_key' => 'test'] )
            ->response( '{
                "images": [
                    "' . base64_encode( 'PNG' ) . '"
                ],
                "error": "only on failure"
            }' )
            ->ensure( 'isolate' )
            ->isolate( Image::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-canvas-v1:0/invoke', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testVectorize() : void
    {
        $response = $this->prisma( 'image', 'bedrock', ['api_key' => 'test'] )
            ->response( json_encode( [
                'embeddings' => [[
                    'embeddingType' => 'IMAGE',
                    'embedding' => [0.1, 0.2, 0.3],
                ]],
            ] ) )
            ->ensure( 'vectorize' )
            ->vectorize( [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-2-multimodal-embeddings-v1:0/invoke', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'nova-multimodal-embed-v1', $body['schemaVersion'] );
            $this->assertEquals( 'SINGLE_EMBEDDING', $body['taskType'] );
            $this->assertEquals( 'GENERIC_INDEX', $body['singleEmbeddingParams']['embeddingPurpose'] );
            $this->assertEquals( 3072, $body['singleEmbeddingParams']['embeddingDimension'] );
            $this->assertEquals( 'STANDARD_IMAGE', $body['singleEmbeddingParams']['image']['detailLevel'] );
            $this->assertEquals( 'png', $body['singleEmbeddingParams']['image']['format'] );
            $this->assertEquals( base64_encode( 'PNG' ), $body['singleEmbeddingParams']['image']['source']['bytes'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
    }


    public function testVectorizeTitan() : void
    {
        $response = $this->prisma( 'image', 'bedrock', ['api_key' => 'test'] )
            ->response( json_encode( [
                'embedding' => [0.1, 0.2, 0.3],
                'metadata' => []
            ] ) )
            ->ensure( 'vectorize' )
            ->model( 'amazon.titan-embed-image-v1' )
            ->vectorize( [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( base64_encode( 'PNG' ), $body['inputImage'] );
            $this->assertEquals( 1024, $body['embeddingConfig']['outputEmbeddingLength'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3]], $response->vectors() );
    }
}
