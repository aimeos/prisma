<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\Attributes\TestWith;
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
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
        $this->assertInstanceOf( Image::class, $file->first() );
    }


    #[TestWith( [true, '2K', 'AUTO'] )]
    #[TestWith( [false, '8K', '1x1'] )]
    public function testImagineTransparent( bool $copyright, string $resolution, string $ratio ) : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/transparent.png']]] )
            ->imagine( 'A watercolor sunflower', [], [
                'transparent' => true,
                'aspect_ratio' => $ratio,
                'output_resolution' => $resolution,
                'rendering_speed' => 'QUALITY',
                'enable_copyright_detection' => $copyright,
            ] );

        $this->assertCount( 1, $this->requests() );
        $request = $this->requests()[0];
        $this->assertSame( 'POST', $request->getMethod() );
        $this->assertSame( 'test', $request->getHeaderLine( 'Api-Key' ) );
        $this->assertSame( 'https://api.ideogram.ai/v1/ideogram-v4/generate-transparent', (string) $request->getUri() );
        $this->assertSame( [
            'text_prompt' => 'A watercolor sunflower',
            'aspect_ratio' => $ratio,
            'output_resolution' => $resolution,
            'rendering_speed' => 'QUALITY',
            'enable_copyright_detection' => $copyright ? 'true' : 'false',
        ], $this->formFields( (string) $request->getBody() ) );
        $this->assertInstanceOf( Image::class, $file->first() );
        $this->assertSame( 'https://example.com/transparent.png', $file->url() );
    }


    public function testImagineTransparentFiltersInvalidValues() : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/transparent.png']]] )
            ->imagine( 'A sunflower', [], [
                'transparent' => true,
                'rendering_speed' => 'FLASH',
                'output_resolution' => '10K',
                'enable_copyright_detection' => null,
            ] );

        $this->assertSame( ['text_prompt' => 'A sunflower'], $this->formFields( (string) $this->requests()[0]->getBody() ) );
    }


    public function testImagineTransparentRejectsReferenceImages() : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );

        try {
            $this->provider()->imagine( 'A sunflower', [Image::fromBinary( 'PNG', 'image/png' )], ['transparent' => true] );
            $this->fail( 'Reference images must not be discarded' );
        } catch( BadRequestException $e ) {
            $this->assertStringContainsString( 'reference images', $e->getMessage() );
            $this->assertSame( [], $this->requests() );
        }
    }


    #[TestWith( ['imagine', ['seed' => 0], 'seed'] )]
    #[TestWith( ['imagine', ['style_preset' => 'WATERCOLOR'], 'style_preset'] )]
    #[TestWith( ['imagine', ['resolution' => '1024x1024'], 'resolution'] )]
    #[TestWith( ['repaint', ['image_weight' => 75], 'image_weight'] )]
    #[TestWith( ['repaint', ['rendering_speed' => 'QUALITY'], 'rendering_speed'] )]
    #[TestWith( ['repaint', ['enable_copyright_detection' => true], 'enable_copyright_detection'] )]
    #[TestWith( ['repaint', ['aspect_ratio' => '1x1', 'resolution' => '1024x1024'], 'aspect_ratio and resolution'] )]
    public function testTransparentRejectsIncompatibleOptions( string $method, array $options, string $error ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );

        try
        {
            if( $method === 'imagine' ) {
                $this->provider()->imagine( 'A sunflower', [], ['transparent' => true] + $options );
            } else {
                $this->provider()->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'A sunflower', ['transparent' => true] + $options );
            }

            $this->fail( 'Incompatible options must not be discarded' );
        }
        catch( BadRequestException $e )
        {
            $this->assertStringContainsString( $error, $e->getMessage() );
            $this->assertSame( [], $this->requests() );
        }
    }


    #[TestWith( ['imagine', 'character_reference_images'] )]
    #[TestWith( ['imagine', 'character_reference_images_mask'] )]
    #[TestWith( ['imagine', 'style_reference_images'] )]
    #[TestWith( ['repaint', 'character_reference_images'] )]
    #[TestWith( ['repaint', 'character_reference_images_mask'] )]
    #[TestWith( ['repaint', 'style_reference_images'] )]
    public function testTransparentRejectsReferenceOptions( string $method, string $name ) : void
    {
        $this->testTransparentRejectsIncompatibleOptions( $method, [$name => [Image::fromBinary( 'PNG', 'image/png' )]], $name );
    }


    #[TestWith( [['aspect_ratio' => '1x1']] )]
    #[TestWith( [['resolution' => '1024x1024']] )]
    public function testRepaintTransparent( array $size ) : void
    {
        $file = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/transparent.png']]] )
            ->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'Make the petals blue', [
                'transparent' => true,
                'magic_prompt' => 'OFF',
                'num_images' => 1,
                'seed' => 0,
            ] + $size );

        $this->assertCount( 1, $this->requests() );
        $request = $this->requests()[0];
        $this->assertSame( 'POST', $request->getMethod() );
        $this->assertSame( 'test', $request->getHeaderLine( 'Api-Key' ) );
        $this->assertSame( 'https://api.ideogram.ai/v1/edit', (string) $request->getUri() );
        $this->assertSame( [
            'prompt' => 'Make the petals blue',
            'transparent_background' => 'true',
            'magic_prompt' => 'OFF',
            'num_images' => '1',
            'seed' => '0',
        ] + $size + ['images' => 'PNG'], $this->formFields( (string) $request->getBody() ) );
        $this->assertStringContainsString( 'Content-Type: image/png', (string) $request->getBody() );
        $this->assertInstanceOf( Image::class, $file->first() );
        $this->assertSame( 'https://example.com/transparent.png', $file->url() );
    }


    #[TestWith( ['imagine', [], 'v1/ideogram-v4/generate'] )]
    #[TestWith( ['imagine', ['style_preset' => 'WATERCOLOR'], 'v1/ideogram-v3/generate'] )]
    #[TestWith( ['repaint', [], 'v1/ideogram-v4/remix'] )]
    #[TestWith( ['repaint', ['style_preset' => 'WATERCOLOR'], 'v1/ideogram-v3/remix'] )]
    public function testTransparentFalsePreservesRouting( string $method, array $options, string $path ) : void
    {
        $provider = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/image.png']]] );

        if( $method === 'imagine' ) {
            $provider->imagine( 'A sunflower', [], ['transparent' => false] + $options );
        } else {
            $provider->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'A sunflower', ['transparent' => false] + $options );
        }

        $this->assertCount( 1, $this->requests() );
        $request = $this->requests()[0];
        $this->assertSame( 'https://api.ideogram.ai/' . $path, (string) $request->getUri() );
        $this->assertStringNotContainsString( 'name="transparent"', (string) $request->getBody() );
        $this->assertStringNotContainsString( 'name="transparent_background"', (string) $request->getBody() );
    }


    private function formFields( string $body ) : array
    {
        preg_match_all( '/Content-Disposition: form-data; name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n(?=--)/s', $body, $matches );
        return array_combine( $matches[1], $matches[2] );
    }
}
