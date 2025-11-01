<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image as ImageFile;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GeminiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImage()
    {
        $file = $this->prisma( 'image', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode( 'PNG' )
                            ]
                        ], [
                            'text' => 'PNG image'
                        ]]
                    ]
                ]]
            ] ) )
            ->image( 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-goog-api-key' ) );
            $this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent', (string) $request->getUri() );
        } );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testRepaint()
    {
        $file = $this->prisma( 'image', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'inlineData' => [
                                'mimeType' => 'image/png',
                                'data' => base64_encode( 'PNG' )
                            ]
                        ]]
                    ]
                ]]
            ] ) )
            ->repaint( ImageFile::fromBinary( 'PNG', 'image/png' ), 'prompt' );

        $this->assertEquals( 'PNG', $file->binary() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
