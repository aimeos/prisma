<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ImagenTest extends TestCase
{
    use MakesPrismaRequests;


    public function testBackground() : void
    {
        $file = $this->prisma( 'image', 'imagen', ['api_key' => 'test', 'project_id' => '123'] )
            ->response( json_encode( [
                'predictions' => [[
                    'mimeType' => 'image/png',
                    'bytesBase64Encoded' => base64_encode( 'PNG' )
                ]]
            ] ) )
            ->ensure( 'background' )
            ->background( ImageFile::fromBinary( 'PNG', 'image/png' ), 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer: test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertEquals( 'https://aiplatform.googleapis.com/v1/projects/123/locations/global/publishers/google/models/imagen-product-recontext-preview-06-30:predict', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testImagine() : void
    {
        $file = $this->prisma( 'image', 'imagen', ['api_key' => 'test', 'project_id' => '123'] )
            ->response( json_encode( [
                'predictions' => [[
                    'mimeType' => 'image/png',
                    'bytesBase64Encoded' => base64_encode( 'PNG' )
                ]]
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://aiplatform.googleapis.com/v1/projects/123/locations/global/publishers/google/models/imagen-4.0-generate-001:predict', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testImagineWithReferences() : void
    {
        $file = $this->prisma( 'image', 'imagen', ['api_key' => 'test', 'project_id' => '123'] )
            ->response( json_encode( [
                'predictions' => [[
                    'mimeType' => 'image/png',
                    'bytesBase64Encoded' => base64_encode( 'PNG' )
                ]]
            ] ) )
            ->ensure( 'imagine' )
            ->imagine( 'prompt', [ImageFile::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://aiplatform.googleapis.com/v1/projects/123/locations/global/publishers/google/models/imagen-3.0-capability-001:predict', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testInpaint() : void
    {
        $file = $this->prisma( 'image', 'imagen', ['api_key' => 'test', 'project_id' => '123'] )
            ->response( json_encode( [
                'predictions' => [[
                    'mimeType' => 'image/png',
                    'bytesBase64Encoded' => base64_encode( 'PNG' )
                ]]
            ] ) )
            ->ensure( 'inpaint' )
            ->inpaint(
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                ImageFile::fromBinary( 'PNG', 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://aiplatform.googleapis.com/v1/projects/123/locations/global/publishers/google/models/imagen-3.0-capability-001:predict', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testUpscale() : void
    {
        $file = $this->prisma( 'image', 'imagen', ['api_key' => 'test', 'project_id' => '123'] )
            ->response( json_encode( [
                'predictions' => [[
                    'mimeType' => 'image/png',
                    'bytesBase64Encoded' => base64_encode( 'PNG' )
                ]]
            ] ) )
            ->ensure( 'upscale' )
            ->upscale( ImageFile::fromBinary( 'PNG', 'image/png' ), 5 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://aiplatform.googleapis.com/v1/projects/123/locations/global/publishers/google/models/imagen-4.0-upscale-preview:predict', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
