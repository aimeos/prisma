<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BlackforestlabsTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $prisma = $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test'] );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "Processing",
            "polling_url": "https://localhost/poll"
        }' );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "Processing"
        }' );

        $file = $prisma->response( '{
                "id": "image_1234567890",
                "status": "Ready",
                "sample": "https://localhost/test.png"
            }' )->ensure( 'imagine' )
            ->imagine( 'prompt', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'x-key' ) );
            $this->assertEquals( 'https://api.bfl.ai/v1/flux-2-pro-preview', (string) $request->getUri() );
        } );

        $this->assertFalse( $file->ready() );
        $this->assertEquals( 'https://localhost/test.png', $file->url() );
    }


    public function testRepaint() : void
    {
        $mock = $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test'] );
        $mock->response( ['polling_url' => 'https://api.bfl.ai/poll', 'cost' => 4] );
        $file = $mock->response( ['status' => 'Ready', 'result' => ['sample' => 'https://example.com/edited.png']] )
            ->model( 'flux-2-pro' )->ensure( 'repaint' )
            ->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'Winter', ['seed' => 0, 'unknown' => true, 'prompt' => 'ignored'] );

        $this->assertPrismaRequest( function( $request ) {
            $this->assertSame( 'https://api.bfl.ai/v1/flux-2-pro', (string) $request->getUri() );
            $this->assertSame( ['prompt' => 'Winter', 'seed' => 0, 'output_format' => 'png', 'input_image' => base64_encode( 'PNG' )],
                json_decode( (string) $request->getBody(), true ) );
        } );

        $this->assertTrue( $file->ready() );
        $this->assertSame( 'https://example.com/edited.png', $file->url() );
        $this->assertSame( 4.0, $file->usage()['used'] );
    }


    public function testInpaint() : void
    {
        $prisma = $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test'] );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "processing",
            "polling_url": "https://localhost/poll"
        }' );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "Processing"
        }' );

        $file = $prisma->response( '{
                "id": "image_1234567890",
                "status": "Ready",
                "sample": "https://localhost/test.png"
            }' )->ensure( 'inpaint' )
            ->inpaint(
                Image::fromBinary( 'PNG', 'image/png' ),
                Image::fromBinary( 'PNG', 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.bfl.ai/v1/flux-pro-1.0-fill', (string) $request->getUri() );
        } );

        $this->assertFalse( $file->ready() );
        $this->assertEquals( 'https://localhost/test.png', $file->url() );
    }


    public function testUncrop() : void
    {
        $prisma = $this->prisma( 'image', 'blackforestlabs', ['api_key' => 'test'] );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "Processing",
            "polling_url": "https://localhost/poll"
        }' );
        $prisma->response( '{
            "id": "image_1234567890",
            "status": "Processing"
        }' );

        $file = $prisma->response( '{
                "id": "image_1234567890",
                "status": "Ready",
                "sample": "https://localhost/test.png"
            }' )->ensure( 'uncrop' )
            ->uncrop( Image::fromBinary( 'PNG', 'image/png' ), 100, 0, 0, 0 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.bfl.ai/v1/flux-pro-1.0-expand', (string) $request->getUri() );
        } );

        $this->assertFalse( $file->ready() );
        $this->assertEquals( 'https://localhost/test.png', $file->url() );
    }
}
