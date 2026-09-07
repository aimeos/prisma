<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class IdeogramTest extends TestCase
{
    use MakesPrismaRequests;


    public function testBackground() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "created": "2000-01-23 04:56:07+00:00",
                "data": [{
                    "prompt": "A photo of a cat",
                    "resolution": "1280x800",
                    "is_image_safe": true,
                    "seed": 12345,
                    "url": "https://placehold.co/10x10.png",
                    "style_type": "GENERAL"
                }]
            }' )
            ->ensure( 'background' )
            ->background( Image::fromBinary( 'PNG', 'image/png' ), 'prompt' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'test', $request->getHeaderLine( 'Api-Key' ) );
            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v3/replace-background', (string) $request->getUri() );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
        $this->assertEquals( 'A photo of a cat', $file->description() );
        $this->assertEquals( [
            "prompt" => "A photo of a cat",
            "resolution" => "1280x800",
            "is_image_safe" => true,
            "seed" => 12345,
            "url" => "https://placehold.co/10x10.png",
            "style_type" => "GENERAL",
            "created" => "2000-01-23 04:56:07+00:00"
        ], $file->meta()->all() );
    }


    public function testDescribe() : void
    {
        $response = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "json_prompt": {
                    "high_level_description": "an image description",
                    "compositional_deconstruction": {
                        "background": "a blue wall",
                        "elements": []
                    },
                    "style_description": {
                        "aesthetics": "minimal"
                    }
                }
            }' )
            ->ensure( 'describe' )
            ->describe(
                Image::fromBinary( 'PNG', 'image/png' ),
                'en',
                ['describe_model_version' => 'V_2']
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v4/describe', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="image_file"', $body );
            $this->assertStringNotContainsString( 'name="describe_model_version"', $body );
        } );

        $this->assertEquals( 'an image description', $response->text() );
        $this->assertEquals( [
            'high_level_description' => 'an image description',
            'compositional_deconstruction' => [
                'background' => 'a blue wall',
                'elements' => [],
            ],
            'style_description' => [
                'aesthetics' => 'minimal',
            ],
        ], $response->structured() );
        $this->assertEquals( $response->structured(), $response->meta()['json_prompt'] );
    }


    public function testDetext() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "base_image_url": "https://placehold.co/10x10.png",
                "seed": 12345,
                "original_image_url": "https://placehold.co/20x20.png"
            }' )
            ->ensure( 'detext' )
            ->detext(
                Image::fromBinary( 'PNG', 'image/png' ),
                ['prompt' => 'A poster', 'seed' => 12345, 'unsupported' => true]
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v3/layerize-text', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="image"', $body );
            $this->assertStringContainsString( 'name="prompt"', $body );
            $this->assertStringContainsString( 'name="seed"', $body );
            $this->assertStringNotContainsString( 'name="unsupported"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( [
            'base_image_url' => 'https://placehold.co/10x10.png',
            'seed' => 12345,
            'original_image_url' => 'https://placehold.co/20x20.png',
        ], $file->meta()->all() );
    }


    public function testErase() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'erase' )
            ->erase(
                Image::fromBinary( 'PNG', 'image/png' ),
                Image::fromBinary( 'PNG', 'image/png' ),
                [
                    'guidance_scale' => 5.5,
                    'num_inference_steps' => 32,
                    'rendering_speed' => 'QUALITY',
                    'seed' => 12345,
                    'unsupported' => true,
                ]
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/remove-object', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="image"', $body );
            $this->assertStringContainsString( 'name="mask"', $body );
            $this->assertStringContainsString( 'name="guidance_scale"', $body );
            $this->assertStringContainsString( 'name="num_inference_steps"', $body );
            $this->assertStringContainsString( 'name="rendering_speed"', $body );
            $this->assertStringContainsString( 'name="seed"', $body );
            $this->assertStringNotContainsString( 'name="unsupported"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
    }


    public function testImagine() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'prompt', [], [
                'enable_copyright_detection' => true,
                'rendering_speed' => 'QUALITY',
                'resolution' => '2048x2048',
                'unsupported' => true,
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v4/generate', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="text_prompt"', $body );
            $this->assertStringContainsString( 'name="enable_copyright_detection"', $body );
            $this->assertStringContainsString( 'name="rendering_speed"', $body );
            $this->assertStringContainsString( 'name="resolution"', $body );
            $this->assertStringNotContainsString( 'name="prompt"', $body );
            $this->assertStringNotContainsString( 'name="unsupported"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testImagineUsesV3ForReferenceImages() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'prompt', [Image::fromBinary( 'PNG', 'image/png' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v3/generate', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="prompt"', $body );
            $this->assertStringContainsString( 'name="style_reference_images[0]"', $body );
            $this->assertStringNotContainsString( 'name="text_prompt"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testInpaint() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'inpaint' )
            ->inpaint(
                Image::fromBinary( 'PNG', 'image/png' ),
                Image::fromBinary( 'PNG', 'image/png' ),
                'prompt'
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v3/edit', (string) $request->getUri() );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testIsolate() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'isolate' )
            ->isolate( Image::fromBinary( 'PNG', 'image/png' ) );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.ideogram.ai/v1/remove-background', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="image"', (string) $request->getBody() );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
    }


    public function testRepaint() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'repaint' )
            ->repaint(
                Image::fromBinary( 'PNG', 'image/png' ),
                'prompt',
                [
                    'enable_copyright_detection' => true,
                    'image_weight' => 75,
                    'rendering_speed' => 'TURBO',
                    'resolution' => '2048x2048',
                    'unsupported' => true,
                ]
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v4/remix', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="image"', $body );
            $this->assertStringContainsString( 'name="text_prompt"', $body );
            $this->assertStringContainsString( 'name="enable_copyright_detection"', $body );
            $this->assertStringContainsString( 'name="image_weight"', $body );
            $this->assertStringContainsString( 'name="rendering_speed"', $body );
            $this->assertStringContainsString( 'name="resolution"', $body );
            $this->assertStringNotContainsString( 'name="prompt"', $body );
            $this->assertStringNotContainsString( 'name="unsupported"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testRepaintUsesV3ForStyleOptions() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'repaint' )
            ->repaint(
                Image::fromBinary( 'PNG', 'image/png' ),
                'prompt',
                ['style_preset' => 'WATERCOLOR']
            );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = (string) $request->getBody();

            $this->assertEquals( 'https://api.ideogram.ai/v1/ideogram-v3/remix', (string) $request->getUri() );
            $this->assertStringContainsString( 'name="prompt"', $body );
            $this->assertStringContainsString( 'name="style_preset"', $body );
            $this->assertStringNotContainsString( 'name="text_prompt"', $body );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }


    public function testUpscale() : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( '{
                "data": [{
                    "url": "https://placehold.co/10x10.png"
                }]
            }' )
            ->ensure( 'upscale' )
            ->upscale( Image::fromBinary( 'PNG', 'image/png' ), 2 );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.ideogram.ai/upscale', (string) $request->getUri() );
        } );

        $this->assertEquals( 'https://placehold.co/10x10.png', $file->url() );
        $this->assertEquals( 'image/png', $file->mimeType() );
    }
}
