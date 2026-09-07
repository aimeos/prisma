<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Detext;
use Aimeos\Prisma\Contracts\Image\Erase;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Ideogram
    extends Base
    implements Background, Describe, Detext, Erase, Imagine, Inpaint, Isolate, Repaint, Upscale
{
    protected int $pollTimeout = 900;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Api-Key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( 'https://api.ideogram.ai' );

        $timeout = $config['poll_timeout'] ?? null;

        if( is_int( $timeout ) && $timeout >= 0 ) {
            $this->pollTimeout = $timeout;
        } elseif( is_string( $timeout ) && preg_match( '/^\d+$/D', $timeout ) ) {
            $this->pollTimeout = (int) $timeout;
        }
    }


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $allowed = $this->allowed( $options, [
            'color_palette', 'magic_prompt', 'rendering_speed', 'seed', 'style_codes',
            'style_preset', 'style_reference_images'
        ] );
        $allowed = $this->sanitize( $allowed, $this->options() );

        $files = $this->toFiles( $options, ['style_reference_images'] );

        $request = $this->payload( ['prompt' => $prompt] + $allowed, ['image' => $image] + $files );
        $response = $this->client()->post( 'v1/ideogram-v3/replace-background', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $this->rejectAsync( $options );
        $request = $this->payload( [], ['image_file' => $image] );
        $response = $this->client()->post( 'v1/ideogram-v4/describe', ['multipart' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        $jsonPrompt = $result['json_prompt'] ?? null;

        if( !is_array( $jsonPrompt ) || !is_string( $jsonPrompt['high_level_description'] ?? null ) ) {
            throw new PrismaException( 'No image description found in response' );
        }

        return TextResponse::fromText( $jsonPrompt['high_level_description'] )
            ->withStructured( $jsonPrompt )
            ->withMeta( $result );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $allowed = $this->allowed( $options, ['prompt', 'seed'] );

        $request = $this->payload( $allowed, ['image' => $image] );
        $response = $this->client()->post( 'v1/ideogram-v3/layerize-text', ['multipart' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        $url = $result['base_image_url'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            throw new PrismaException( 'No image data found in response' );
        }

        return FileResponse::fromFiles( [Image::fromUrl( $url )] )->withMeta( $result );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $allowed = $this->allowed( $options, [
            'guidance_scale', 'num_inference_steps', 'rendering_speed', 'seed'
        ] );
        $allowed = $this->sanitize( $allowed, $this->options() );

        $request = $this->payload( $allowed, ['image' => $image, 'mask' => $mask] );
        $response = $this->client()->post( 'v1/remove-object', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $async = ( $options['async'] ?? false ) === true;
        unset( $options['async'] );

        if( ( $options['transparent'] ?? false ) === true )
        {
            if( $images ) {
                throw new BadRequestException( 'Transparent generation does not support reference images; generate the image first, then call isolate()' );
            }

            $allowed = $this->transparentOptions( $options, [
                'aspect_ratio', 'enable_copyright_detection', 'output_resolution', 'rendering_speed'
            ] );
            $allowed = $this->sanitize( $allowed, [
                'output_resolution' => ['1K', '2K', '4K', '8K'],
                'rendering_speed' => ['TURBO', 'DEFAULT', 'QUALITY'],
            ] );

            $request = $this->payload( ['text_prompt' => $prompt] + $allowed );
            $path = $async ? 'async/generate-transparent' : 'generate-transparent';
            $response = $this->client()->post( 'v1/ideogram-v4/' . $path, ['multipart' => $request] );

            return $async ? $this->toAsyncResponse( $response ) : $this->toFileResponse( $response );
        }

        $v3options = $this->allowed( $options, [
            'aspect_ratio', 'character_reference_images', 'character_reference_images_mask', 'color_palette',
            'magic_prompt', 'negative_prompt', 'seed', 'style_codes', 'style_preset', 'style_type'
        ] );
        $v3options = $this->sanitize( $v3options, $this->options() );

        if( empty( $images ) && empty( $v3options ) )
        {
            $allowed = $this->allowed( $options, [
                'enable_copyright_detection', 'rendering_speed', 'resolution'
            ] );
            $allowed = $this->sanitize( $allowed, $this->v4Options() );

            $request = $this->payload( ['text_prompt' => $prompt] + $allowed );
            $path = $async ? 'async/generate' : 'generate';
            $response = $this->client()->post( 'v1/ideogram-v4/' . $path, ['multipart' => $request] );

            return $async ? $this->toAsyncResponse( $response ) : $this->toFileResponse( $response );
        }

        if( $async ) {
            throw new BadRequestException( 'Async generation does not support reference images or V3 options' );
        }

        $allowed = $this->allowed( $options, [
            'aspect_ratio', 'character_reference_images', 'character_reference_images_mask', 'color_palette',
            'magic_prompt', 'negative_prompt', 'rendering_speed', 'resolution', 'seed', 'style_codes',
            'style_preset', 'style_type'
        ] );
        $allowed = $this->sanitize( $allowed, $this->options() );
        $files = $this->toFiles( $options, ['character_reference_images', 'character_reference_images_mask'] );

        $request = $this->payload( ['prompt' => $prompt] + $allowed, ['style_reference_images' => $images] + $files );
        $response = $this->client()->post( 'v1/ideogram-v3/generate', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $allowed = $this->allowed( $options, [
            'character_reference_images', 'character_reference_images_mask', 'color_palette',
            'magic_prompt', 'rendering_speed', 'seed', 'style_codes', 'style_preset',
            'style_reference_images', 'style_type'
        ] );
        $allowed = $this->sanitize( $allowed, $this->options() );
        $files = $this->toFiles( $options, ['character_reference_images', 'character_reference_images_mask', 'style_reference_images'] );

        $request = $this->payload( ['prompt' => $prompt] + $allowed, ['image' => $image, 'mask' => $mask] + $files );
        $response = $this->client()->post( 'v1/ideogram-v3/edit', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $request = $this->payload( [], ['image' => $image] );
        $response = $this->client()->post( 'v1/remove-background', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );

        if( ( $options['transparent'] ?? false ) === true )
        {
            $allowed = $this->transparentOptions( $options, [
                'aspect_ratio', 'magic_prompt', 'num_images', 'resolution', 'seed'
            ] );
            $allowed = $this->sanitize( $allowed, $this->options() );

            if( isset( $allowed['aspect_ratio'], $allowed['resolution'] ) ) {
                throw new BadRequestException( 'Transparent repaint cannot combine aspect_ratio and resolution' );
            }

            // The edit endpoint expects the repeated field "images", not "image" or "images[0]".
            $request = $this->payload( ['prompt' => $prompt, 'transparent_background' => 'true'] + $allowed, ['images' => $image] );
            $response = $this->client()->post( 'v1/edit', ['multipart' => $request] );

            return $this->toFileResponse( $response );
        }

        $v3options = $this->allowed( $options, [
            'aspect_ratio', 'character_reference_images', 'character_reference_images_mask', 'color_palette',
            'magic_prompt', 'negative_prompt', 'seed', 'style_codes', 'style_preset',
            'style_reference_images', 'style_type'
        ] );
        $v3options = $this->sanitize( $v3options, $this->options() );

        if( empty( $v3options ) )
        {
            $allowed = $this->allowed( $options, [
                'enable_copyright_detection', 'image_weight', 'rendering_speed', 'resolution'
            ] );
            $allowed = $this->sanitize( $allowed, $this->v4Options() );

            $request = $this->payload( ['text_prompt' => $prompt] + $allowed, ['image' => $image] );
            $response = $this->client()->post( 'v1/ideogram-v4/remix', ['multipart' => $request] );

            return $this->toFileResponse( $response );
        }

        $allowed = $this->allowed( $options, [
            'aspect_ratio', 'character_reference_images', 'character_reference_images_mask', 'color_palette',
            'image_weight', 'magic_prompt', 'negative_prompt', 'rendering_speed', 'resolution', 'seed',
            'style_codes', 'style_preset', 'style_reference_images', 'style_type'
        ] );
        $allowed = $this->sanitize( $allowed, $this->options() );
        $files = $this->toFiles( $options, ['character_reference_images', 'character_reference_images_mask', 'style_reference_images'] );

        $request = $this->payload( ['prompt' => $prompt] + $allowed, ['image' => $image] + $files );
        $response = $this->client()->post( 'v1/ideogram-v3/remix', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        $this->rejectAsync( $options );
        $allowed = $this->allowed( $options, ['detail', 'magic_prompt_option', 'prompt', 'resemblance', 'seed'] );
        $allowed = $this->sanitize( $allowed, $this->options() );

        $request = $this->payload( ['image_request' => json_encode( $allowed )], ['image_file' => $image] );
        $response = $this->client()->post( 'upscale', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    /**
     * Returns the list of available options and their possible values.
     *
     * @return array<string, array<int, mixed>|null> List of option names and their possible values
     */
    protected function options() : array
    {
        return [
            'aspect_ratio' => [
                '1x3', '3x1', '1x2', '2x1', '9x16', '16x9', '10x16', '16x10',
                '2x3', '3x2', '3x4', '4x3', '4x5', '5x4', '1x1'
            ],
            'magic_prompt' => ['AUTO', 'ON', 'OFF'],
            'rendering_speed' => ['FLASH', 'TURBO', 'DEFAULT', 'QUALITY'],
            'style_preset' => [
                '80S_ILLUSTRATION', '90S_NOSTALGIA', 'ABSTRACT_ORGANIC', 'ANALOG_NOSTALGIA', 'ART_BRUT',
                'ART_DECO', 'ART_POSTER', 'AURA', 'AVANT_GARDE', 'BAUHAUS', 'BLUEPRINT', 'BLURRY_MOTION',
                'BRIGHT_ART', 'C4D_CARTOON', 'CHILDRENS_BOOK', 'COLLAGE', 'COLORING_BOOK_I', 'COLORING_BOOK_II',
                'CUBISM', 'DARK_AURA', 'DOODLE', 'DOUBLE_EXPOSURE', 'DRAMATIC_CINEMA', 'EDITORIAL',
                'EMOTIONAL_MINIMAL', 'ETHEREAL_PARTY', 'EXPIRED_FILM', 'FLAT_ART', 'FLAT_VECTOR', 'FOREST_REVERIE',
                'GEO_MINIMALIST', 'GLASS_PRISM', 'GOLDEN_HOUR', 'GRAFFITI_I', 'GRAFFITI_II', 'HALFTONE_PRINT',
                'HIGH_CONTRAST', 'HIPPIE_ERA', 'ICONIC', 'JAPANDI_FUSION', 'JAZZY', 'LONG_EXPOSURE',
                'MAGAZINE_EDITORIAL', 'MINIMAL_ILLUSTRATION', 'MIXED_MEDIA', 'MONOCHROME', 'NIGHTLIFE',
                'OIL_PAINTING', 'OLD_CARTOONS', 'PAINT_GESTURE', 'POP_ART', 'RETRO_ETCHING', 'RIVIERA_POP',
                'SPOTLIGHT_80S', 'STYLIZED_RED', 'SURREAL_COLLAGE', 'TRAVEL_POSTER', 'VINTAGE_GEO',
                'VINTAGE_POSTER', 'WATERCOLOR', 'WEIRD', 'WOODBLOCK_PRINT'
            ],
            'resolution' => [
                '512x1536', '576x1408', '576x1472', '576x1536', '640x1344', '640x1408', '640x1472',
                '640x1536', '704x1152', '704x1216', '704x1280', '704x1344', '704x1408', '704x1472',
                '736x1312', '768x1088', '768x1216', '768x1280', '768x1344', '800x1280', '832x960',
                '832x1024', '832x1088', '832x1152', '832x1216', '832x1248', '864x1152', '896x960',
                '896x1024', '896x1088', '896x1120', '896x1152', '960x832', '960x896', '960x1024',
                '960x1088', '1024x832', '1024x896', '1024x960', '1024x1024', '1088x768', '1088x832',
                '1088x896', '1088x960', '1120x896', '1152x704', '1152x832', '1152x864', '1152x896',
                '1216x704', '1216x768', '1216x832', '1248x832', '1280x704', '1280x768', '1280x800',
                '1312x736', '1344x640', '1344x704', '1344x768', '1408x576', '1408x640', '1408x704',
                '1472x576', '1472x640', '1472x704', '1536x512', '1536x576', '1536x640'
            ]
        ];
    }


    /**
     * Returns the options supported by Ideogram V4 endpoints.
     *
     * @return array<string, array<int, mixed>|null> List of option names and their possible values
     */
    protected function v4Options() : array
    {
        return [
            'rendering_speed' => ['FLASH', 'TURBO', 'DEFAULT', 'QUALITY'],
            'resolution' => null,
        ];
    }


    /**
     * Validates options for transparent endpoints and encodes boolean multipart values.
     *
     * @param array<string, mixed> $options Provider specific options
     * @param array<string> $names Supported option names
     * @return array<string, mixed> Options to send to Ideogram
     */
    protected function transparentOptions( array $options, array $names ) : array
    {
        unset( $options['transparent'], $options['async'] );
        $allowed = $this->allowed( $options, $names );
        $unsupported = array_diff_key( $options, $allowed );

        if( $unsupported ) {
            throw new BadRequestException( 'Unsupported options with transparent=true: ' . implode( ', ', array_keys( $unsupported ) ) );
        }

        foreach( $allowed as $key => $value )
        {
            if( $value === null ) {
                unset( $allowed[$key] );
            } elseif( is_bool( $value ) ) {
                $allowed[$key] = $value ? 'true' : 'false';
            }
        }

        return $allowed;
    }


    /**
     * Rejects async requests for methods without a pollable endpoint.
     *
     * @param array<string, mixed> $options Provider specific options
     */
    protected function rejectAsync( array $options ) : void
    {
        if( ( $options['async'] ?? false ) === true ) {
            throw new BadRequestException( 'Async is only supported by Ideogram V4 imagine()' );
        }
    }


    /**
     * Converts an accepted generation into a deferred FileResponse.
     *
     * @param ResponseInterface $response Guzzle HTTP response
     * @return FileResponse File response that polls its own generation
     */
    protected function toAsyncResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );
        $result = $this->fromJson( $response );
        $id = $result['generation_id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            throw new PrismaException( 'No generation ID found in response' );
        }

        return FileResponse::fromAsync( function( FileResponse $file ) use ( $id ) : bool {
            $response = $this->client()->get( 'v1/generations/' . rawurlencode( $id ) );
            $this->validate( $response );
            $result = $this->fromJson( $response );
            $file->withMeta( ['generation_id' => $id] + $result );

            if( ( $result['status'] ?? null ) === 'pending' ) {
                return false;
            }

            if( ( $result['status'] ?? null ) === 'failed' ) {
                $reason = $result['failure_reason'] ?? null;
                throw new PrismaException( 'Ideogram generation failed' . ( is_string( $reason ) ? ': ' . $reason : '' ) );
            }

            if( ( $result['status'] ?? null ) !== 'completed' ) {
                throw new PrismaException( 'Unknown Ideogram generation status' );
            }

            $generated = $this->fromResult( $result );

            foreach( $generated->files() as $image ) {
                $file->add( $image );
            }

            $file->withDescription( $generated->description() )
                ->withMeta( ['generation_id' => $id] + $result + $generated->meta()->all() );

            return true;
        }, 2, $this->pollTimeout )->withMeta( ['generation_id' => $id] );
    }


    /**
     * Converts the HTTP response into a FileResponse instance.
     *
     * @param ResponseInterface $response Guzzle HTTP response
     * @return FileResponse File response instance
     */
    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        return $this->fromResult( $this->fromJson( $response ) );
    }


    /**
     * Maps completed synchronous or asynchronous generation data to image files.
     *
     * @param array<string, mixed> $result Decoded generation result
     * @return FileResponse Generated images with their description and metadata
     */
    protected function fromResult( array $result ) : FileResponse
    {
        $files = [];
        $first = [];

        $dataItems = $result['data'] ?? [];

        foreach( is_array( $dataItems ) ? $dataItems : [] as $item )
        {
            if( is_array( $item ) && is_string( $item['url'] ?? null ) && $item['url'] !== '' ) {
                $files[] = Image::fromUrl( $item['url'] );
                $first = $first ?: $item;
            }
        }

        if( empty( $files ) ) {
            throw new PrismaException( 'No image data found in response' );
        }

        $prompt = $first['prompt'] ?? null;

        return FileResponse::fromFiles( $files )
            ->withDescription( is_string( $prompt ) ? $prompt : null )
            ->withMeta( $first + ['created' => $result['created'] ?? null] );
    }


    /**
     * Converts the given options into a list of files.
     *
     * @param array<string, mixed> $options Associative list of name/value pairs
     * @param array<string> $names List of option names to convert
     * @return array<string, array<int, Image>> Associative list of file name/Image instances
     */
    protected function toFiles( array $options, array $names ) : array
    {
        $files = [];

        foreach( $names as $name )
        {
            foreach( (array) ( $options[$name] ?? [] ) as $file ) {
                if( $file instanceof Image ) {
                    $files[$name][] = $file;
                }
            }
        }

        return $files;
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 422 )
        {
            $error = @$this->fromJson( $response )['error'] ?: $response->getReasonPhrase();
            throw new \Aimeos\Prisma\Exceptions\ForbiddenException( is_string( $error ) ? $error : '' );
        }

        parent::validate( $response );
    }
}
