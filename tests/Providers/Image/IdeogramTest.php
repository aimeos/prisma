<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
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


    #[TestWith( [false, false, 'generate'] )]
    #[TestWith( [false, true, 'async/generate'] )]
    #[TestWith( [true, false, 'generate-transparent'] )]
    #[TestWith( [true, true, 'async/generate-transparent'] )]
    public function testGenerationModes( bool $transparent, bool $async, string $path ) : void
    {
        $options = $transparent ? ['output_resolution' => '1K', 'aspect_ratio' => 'AUTO'] : ['resolution' => '1K'];
        $response = $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( $async ? ['generation_id' => 'job-1'] : ['data' => [['url' => 'https://example.com/image.png']]] )
            ->imagine( 'A sunflower', [], ['async' => $async, 'transparent' => $transparent] + $options );

        $this->assertCount( 1, $this->requests() );
        $request = $this->requests()[0];
        $this->assertSame( 'POST', $request->getMethod() );
        $this->assertSame( 'test', $request->getHeaderLine( 'Api-Key' ) );
        $this->assertSame( 'https://api.ideogram.ai/v1/ideogram-v4/' . $path, (string) $request->getUri() );
        $this->assertSame( ['text_prompt' => 'A sunflower'] + $options, $this->formFields( (string) $request->getBody() ) );

        if( $async ) {
            $this->assertSame( 'job-1', $response->meta()['generation_id'] );
        } else {
            $this->assertTrue( $response->ready() );
            $this->assertSame( 'https://example.com/image.png', $response->url() );
        }

        $this->assertCount( 1, $this->requests() );
    }


    #[TestWith( [false] )]
    #[TestWith( [true] )]
    public function testAsyncPolling( bool $transparent ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test', 'poll_timeout' => 0] );
        $this->response( ['generation_id' => 'job-1'] );
        $this->response( ['generation_id' => 'job-1', 'status' => 'pending', 'created' => '2026-09-07T12:00:00Z'] );
        $this->response( [
            'generation_id' => 'job-1',
            'status' => 'completed',
            'created' => '2026-09-07T12:00:00Z',
            'response_type' => 'url',
            'usage_cost_usd_micros' => 12345,
            'data' => [
                ['url' => 'https://example.com/first.png', 'prompt' => 'A sunflower', 'seed' => 42, 'is_image_safe' => true],
                ['url' => 'https://example.com/second.png'],
            ],
        ] );

        $response = $this->provider()->imagine( 'A sunflower', [], ['async' => true, 'transparent' => $transparent] );
        $this->assertCount( 1, $this->requests() );
        $this->assertFalse( $response->ready() );
        $this->assertCount( 2, $this->requests() );
        $this->assertSame( 'pending', $response->meta()['status'] );
        $this->assertTrue( $response->ready() );
        $this->assertCount( 3, $this->requests() );

        $this->assertCount( 2, $response->files() );
        $this->assertInstanceOf( Image::class, $response->first() );
        $this->assertSame( 'https://example.com/first.png', $response->url() );
        $this->assertSame( 'https://example.com/second.png', $response->files()[1]->url() );
        $this->assertSame( 'A sunflower', $response->description() );
        $this->assertSame( 'completed', $response->meta()['status'] );
        $this->assertSame( 'job-1', $response->meta()['generation_id'] );
        $this->assertSame( '2026-09-07T12:00:00Z', $response->meta()['created'] );
        $this->assertSame( 12345, $response->meta()['usage_cost_usd_micros'] );
        $this->assertSame( 42, $response->meta()['seed'] );
        $this->assertTrue( $response->meta()['is_image_safe'] );
        $this->assertTrue( $response->ready() );
        $this->assertCount( 3, $this->requests(), 'Resolved responses must not poll again' );

        foreach( array_slice( $this->requests(), 1 ) as $request ) {
            $this->assertSame( 'GET', $request->getMethod() );
            $this->assertSame( 'test', $request->getHeaderLine( 'Api-Key' ) );
            $this->assertSame( 'https://api.ideogram.ai/v1/generations/job-1', (string) $request->getUri() );
        }
    }


    public function testSyncAndAsyncCallsKeepIndependentJobs() : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );
        $this->response( ['generation_id' => 'job-1'] );
        $this->response( ['data' => [['url' => 'https://example.com/sync.png']]] );
        $this->response( ['generation_id' => 'job-2'] );
        $this->response( ['status' => 'pending'] );
        $this->response( ['status' => 'completed', 'data' => [['url' => 'https://example.com/second.png']]] );
        $this->response( ['status' => 'completed', 'data' => [['url' => 'https://example.com/first.png']]] );

        $first = $this->provider()->imagine( 'First', [], ['async' => true] );
        $sync = $this->provider()->imagine( 'Sync' );
        $second = $this->provider()->imagine( 'Second', [], ['async' => true, 'transparent' => true] );
        $this->assertCount( 3, $this->requests() );
        $this->assertTrue( $sync->ready() );
        $this->assertSame( 'https://example.com/sync.png', $sync->url() );
        $this->assertFalse( $first->ready() );
        $this->assertSame( 'https://example.com/second.png', $second->url(), 'File access must resolve an unfinished job' );
        $this->assertSame( 'https://example.com/first.png', $first->url() );
        $this->assertSame( 'job-1', $first->meta()['generation_id'] );
        $this->assertSame( 'job-2', $second->meta()['generation_id'] );
        $this->assertSame( [
            '/v1/ideogram-v4/async/generate',
            '/v1/ideogram-v4/generate',
            '/v1/ideogram-v4/async/generate-transparent',
            '/v1/generations/job-1',
            '/v1/generations/job-2',
            '/v1/generations/job-1',
        ], array_map( fn( $request ) => $request->getUri()->getPath(), $this->requests() ) );
    }


    public function testAsyncEncodesGenerationId() : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );
        $this->response( ['generation_id' => 'job/one?x#='] );
        $this->response( ['status' => 'pending'] );

        $response = $this->provider()->imagine( 'A sunflower', [], ['async' => true] );
        $this->assertFalse( $response->ready() );
        $this->assertSame( 'https://api.ideogram.ai/v1/generations/job%2Fone%3Fx%23%3D', (string) $this->requests()[1]->getUri() );
    }


    #[TestWith( [[]] )]
    #[TestWith( [['generation_id' => '']] )]
    #[TestWith( [['generation_id' => null]] )]
    #[TestWith( [['generation_id' => 123]] )]
    #[TestWith( [['generation_id' => ['job-1']]] )]
    public function testAsyncRejectsMissingGenerationId( array $result ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )->response( $result );
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'No generation ID found in response' );
        $this->provider()->imagine( 'A sunflower', [], ['async' => true] );
    }


    #[TestWith( [['status' => 'failed'], 'Ideogram generation failed'] )]
    #[TestWith( [['status' => 'failed', 'failure_reason' => 'content_policy_violation'], 'content_policy_violation'] )]
    #[TestWith( [['status' => 'unexpected'], 'Unknown Ideogram generation status'] )]
    #[TestWith( [[], 'Unknown Ideogram generation status'] )]
    #[TestWith( [['status' => 'completed'], 'No image data found'] )]
    #[TestWith( [['status' => 'completed', 'data' => []], 'No image data found'] )]
    #[TestWith( [['status' => 'completed', 'data' => 'invalid'], 'No image data found'] )]
    #[TestWith( [['status' => 'completed', 'data' => [['url' => null], ['url' => ''], ['url' => 42]]], 'No image data found'] )]
    #[TestWith( ['not json', 'Invalid JSON response'] )]
    public function testAsyncRejectsFailedOrMalformedResults( string|array $result, string $message ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );
        $this->response( ['generation_id' => 'job-1'] );
        $this->response( $result );
        $response = $this->provider()->imagine( 'A sunflower', [], ['async' => true] );

        try {
            $response->ready();
            $this->fail( 'Invalid async results must stop polling' );
        } catch( PrismaException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
            $this->assertCount( 2, $this->requests() );
        }
    }


    #[TestWith( [false, 400] )]
    #[TestWith( [false, 401] )]
    #[TestWith( [false, 422] )]
    #[TestWith( [false, 429] )]
    #[TestWith( [true, 404] )]
    #[TestWith( [true, 429] )]
    #[TestWith( [true, 500] )]
    public function testAsyncHttpErrors( bool $poll, int $status ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );

        if( $poll ) {
            $this->response( ['generation_id' => 'job-1'] );
        }

        $this->response( ['error' => $status === 422 ? 'test error' : ['message' => 'test error']], [], $status );
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'test error' );
        $this->provider()->imagine( 'A sunflower', [], ['async' => true] )->ready();
    }


    #[TestWith( [1] )]
    #[TestWith( ['1'] )]
    public function testAsyncPollingTimeout( int|string $timeout ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test', 'poll_timeout' => $timeout] );
        $this->response( ['generation_id' => 'job-1'] );
        $this->response( ['status' => 'pending'] );
        $this->response( ['status' => 'pending'] );
        $response = $this->provider()->imagine( 'A sunflower', [], ['async' => true] );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Asynchronous operation timed out after 1 seconds' );
        $response->files();
    }


    #[TestWith( [false, ['seed' => 0]] )]
    #[TestWith( [false, ['style_preset' => 'WATERCOLOR']] )]
    #[TestWith( [false, ['aspect_ratio' => '1x1']] )]
    #[TestWith( [true, []] )]
    public function testAsyncRejectsV3Fallback( bool $reference, array $options ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );
        $images = $reference ? [Image::fromBinary( 'PNG', 'image/png' )] : [];

        try {
            $this->provider()->imagine( 'A sunflower', $images, ['async' => true] + $options );
            $this->fail( 'Async must not fall back to V3' );
        } catch( BadRequestException $e ) {
            $this->assertStringContainsString( 'reference images or V3 options', $e->getMessage() );
            $this->assertSame( [], $this->requests() );
        }
    }


    #[TestWith( ['background'] )]
    #[TestWith( ['describe'] )]
    #[TestWith( ['detext'] )]
    #[TestWith( ['erase'] )]
    #[TestWith( ['inpaint'] )]
    #[TestWith( ['isolate'] )]
    #[TestWith( ['repaint'] )]
    #[TestWith( ['repaint', ['transparent' => true]] )]
    #[TestWith( ['repaint', ['style_preset' => 'WATERCOLOR']] )]
    #[TestWith( ['upscale'] )]
    public function testAsyncRejectsUnsupportedMethods( string $method, array $options = [] ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] );
        $image = Image::fromBinary( 'PNG', 'image/png' );
        $args = match( $method ) {
            'background', 'repaint' => [$image, 'A sunflower'],
            'describe' => [$image, 'en'],
            'erase' => [$image, $image],
            'inpaint' => [$image, $image, 'A sunflower'],
            'upscale' => [$image, 2],
            default => [$image],
        };

        try {
            $this->provider()->$method( ...[...$args, ['async' => true] + $options] );
            $this->fail( 'Unsupported methods must not silently run synchronously' );
        } catch( BadRequestException $e ) {
            $this->assertStringContainsString( 'Async is only supported', $e->getMessage() );
            $this->assertSame( [], $this->requests() );
        }
    }


    #[TestWith( ['imagine', ['style_preset' => 'WATERCOLOR'], 'v1/ideogram-v3/generate'] )]
    #[TestWith( ['repaint', [], 'v1/ideogram-v4/remix'] )]
    #[TestWith( ['repaint', ['style_preset' => 'WATERCOLOR'], 'v1/ideogram-v3/remix'] )]
    #[TestWith( ['repaint', ['transparent' => true], 'v1/edit'] )]
    public function testAsyncFalsePreservesRouting( string $method, array $options, string $path ) : void
    {
        $this->prisma( 'image', 'ideogram', ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/image.png']]] );
        $options = ['async' => false] + $options;

        $response = $method === 'imagine'
            ? $this->provider()->imagine( 'A sunflower', [], $options )
            : $this->provider()->repaint( Image::fromBinary( 'PNG', 'image/png' ), 'A sunflower', $options );

        $this->assertTrue( $response->ready() );
        $this->assertCount( 1, $this->requests() );
        $this->assertSame( 'https://api.ideogram.ai/' . $path, (string) $this->requests()[0]->getUri() );
        $this->assertStringNotContainsString( 'name="async"', (string) $this->requests()[0]->getBody() );
    }


    private function formFields( string $body ) : array
    {
        preg_match_all( '/Content-Disposition: form-data; name="([^"]+)"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.*?)\r\n(?=--)/s', $body, $matches );
        return array_combine( $matches[1], $matches[2] );
    }
}
