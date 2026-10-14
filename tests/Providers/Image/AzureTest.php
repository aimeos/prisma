<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AzureTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'azure', ['region' => 'westeurope'] );
    }


    public function testRecognize() : void
    {
        $response = $this->prisma( 'image', 'azure', ['api_key' => 'test', 'region' => 'westeurope'] )
            ->response( json_encode( [
                'modelVersion' => '2023-10-01',
                'metadata' => ['width' => 800, 'height' => 600],
                'readResult' => ['blocks' => [
                    ['lines' => [['text' => 'This is'], ['text' => 'a test']]],
                    ['lines' => [['text' => 'Second block', 'words' => [['text' => 'Second', 'confidence' => 0.99]]]]],
                ]],
            ] ) )
            ->ensure( 'recognize' )
            ->recognize( Image::fromBinary( 'PNG', 'image/png' ), ['model-version' => 'latest', 'unknown' => 'ignored'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals(
                'https://westeurope.api.cognitive.microsoft.com/computervision/imageanalysis:analyze?api-version=2024-02-01&features=read&model-version=latest',
                (string) $request->getUri()
            );
            $this->assertEquals( 'test', $request->getHeaderLine( 'Ocp-Apim-Subscription-Key' ) );
            $this->assertEquals( 'application/octet-stream', $request->getHeaderLine( 'Content-Type' ) );
            $this->assertEquals( 'PNG', $request->getBody()->getContents() );
        } );

        $this->assertEquals( "This is\na test\n\nSecond block", $response->text() );
        $this->assertEquals( 0.99, $response->structured()[1]['lines'][0]['words'][0]['confidence'] );
        $this->assertEquals( '2023-10-01', $response->meta()['modelVersion'] );
        $this->assertEquals( 800, $response->meta()['metadata']['width'] );
    }


    public function testRecognizeError() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'image', 'azure', ['api_key' => 'test'] )
            ->response( ['error' => ['code' => 'InvalidRequest', 'message' => 'Invalid image']], status: 400, reason: 'Bad Request' )
            ->ensure( 'recognize' )
            ->recognize( Image::fromBinary( 'PNG', 'image/png' ) );
    }


    public function testRecognizeCustomUrl() : void
    {
        $this->prisma( 'image', 'azure', ['api_key' => 'test', 'url' => 'https://myvision.cognitiveservices.azure.com/'] )
            ->response( json_encode( ['readResult' => ['blocks' => []]] ) )
            ->ensure( 'recognize' )
            ->recognize( Image::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals(
                'https://myvision.cognitiveservices.azure.com/computervision/imageanalysis:analyze?api-version=2024-02-01&features=read',
                (string) $request->getUri()
            );
        } );
    }


    public function testRecognizeUrl() : void
    {
        $response = $this->prisma( 'image', 'azure', ['api_key' => 'test'] )
            ->response( json_encode( ['readResult' => ['blocks' => []]] ) )
            ->ensure( 'recognize' )
            ->recognize( Image::fromUrl( 'https://example.com/image.png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertStringStartsWith( 'https://eastus.api.cognitive.microsoft.com/', (string) $request->getUri() );
            $this->assertEquals( '{"url":"https:\/\/example.com\/image.png"}', $request->getBody()->getContents() );
        } );

        $this->assertEquals( '', $response->text() );
    }
}
