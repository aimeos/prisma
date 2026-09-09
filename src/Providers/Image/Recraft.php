<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Recraft extends Base implements
    // background

    // erase
    \Aimeos\Prisma\Contracts\Image\Erase,

    // imagine
    \Aimeos\Prisma\Contracts\Image\Imagine,

    // inpaint
    \Aimeos\Prisma\Contracts\Image\Inpaint,

    // isolate
    \Aimeos\Prisma\Contracts\Image\Isolate,

    // upscale
    Repaint,
    Uncrop
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://external.api.recraft.ai' ) );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        return $this->request( 'eraseRegion', [
            'image_url' => $this->imageUrl( $image ),
            'mask_url' => $this->imageUrl( $mask ),
        ] + $this->allowed( $options, ['response_format'] ) );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->request( 'imageToImage', [
            'prompt' => $prompt,
            'image_url' => $this->imageUrl( $image ),
        ] + $this->generationOptions( $options, 'recraftv4_1' )
            + $this->allowed( $options, ['strength', 'random_seed'] ) + ['strength' => 0.5] );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $data = ['prompt' => $prompt];

        if( $images )
        {
            if( count( $images ) > 10 || isset( $options['style_id'] ) ) {
                throw new BadRequestException( 'Use at most 10 style reference images and do not combine them with style_id' );
            }

            foreach( $images as $image )
            {
                if( !( $image instanceof Image ) ) {
                    throw new BadRequestException( 'Style references must be Image objects' );
                }

                $data['style_reference_urls'][] = $this->imageUrl( $image );
            }
        }

        $allowed = $this->generationOptions( $options, $images ? 'recraftv4_styles' : 'recraftv4_1' );
        $allowed += $this->allowed( $options, ['size', 'random_seed'] );

        return $this->request( 'generations', $data + $allowed );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        if( min( $top, $right, $bottom, $left ) < 0 || max( $top, $right, $bottom, $left ) > 4096 ) {
            throw new BadRequestException( 'Outpainting margins must be between 0 and 4096 pixels' );
        }

        if( isset( $options['size'] ) ) {
            throw new BadRequestException( 'Outpainting size cannot be combined with pixel margins' );
        }

        return $this->request( 'outpaint', [
            'image_url' => $this->imageUrl( $image ),
            'expand_top' => $top,
            'expand_right' => $right,
            'expand_bottom' => $bottom,
            'expand_left' => $left,
        ] + $this->generationOptions( $options, 'recraftv3' )
            + $this->allowed( $options, ['prompt', 'zoom_out_percentage'] )
            + ['prompt' => 'Extend the image naturally'] );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->request( 'inpaint', [
            'prompt' => $prompt,
            'image_url' => $this->imageUrl( $image ),
            'mask_url' => $this->imageUrl( $mask ),
        ] + $this->generationOptions( $options, 'recraftv3' ) );
    }


    /**
     * @param array<string, mixed> $options Provider options
     * @return array<string, mixed> Generation parameters
     */
    protected function generationOptions( array $options, string $default ) : array
    {
        return ['model' => $this->modelName( $default )] + $this->allowed( $options, [
            'n', 'style', 'style_id', 'style_match', 'response_format',
            'negative_prompt', 'text_layout', 'controls'
        ] );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        return $this->request( 'removeBackground', [
            'image_url' => $this->imageUrl( $image ),
        ] + $this->allowed( $options, ['response_format'] ) );
    }


    protected function imageUrl( Image $image ) : string
    {
        return $image->url() ?? 'data:' . $image->mimeType() . ';base64,' . $image->base64();
    }


    /**
     * @param array<string, mixed> $data Request parameters
     */
    protected function request( string $endpoint, array $data ) : FileResponse
    {
        $response = $this->client()->post( 'v1/images/' . $endpoint, ['json' => $data] );
        $this->validate( $response );
        $result = $this->fromJson( $response );
        $entries = $result['data'] ?? ( isset( $result['image'] ) ? [$result['image']] : [] );

        if( !is_array( $entries ) || !$entries ) {
            throw new PrismaException( 'No image data found in Recraft response' );
        }

        $file = FileResponse::fromFiles( [] );

        foreach( $entries as $entry )
        {
            if( !is_array( $entry ) ) {
                throw new PrismaException( 'Invalid image data in Recraft response' );
            }

            if( is_string( $entry['b64_json'] ?? null ) && $entry['b64_json'] !== '' )
            {
                $binary = base64_decode( $entry['b64_json'], true );

                if( $binary === false || $binary === '' ) {
                    throw new PrismaException( 'Invalid base64 image in Recraft response' );
                }

                $file->add( Image::fromBinary( $binary ) );
            }
            elseif( is_string( $entry['url'] ?? null ) && $entry['url'] !== '' ) {
                $file->add( Image::fromUrl( $entry['url'] ) );
            }
            else {
                throw new PrismaException( 'No image data found in Recraft response' );
            }
        }

        return $file->withMeta( $result )->withUsage( is_numeric( $result['credits'] ?? null ) ? (float) $result['credits'] : null );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $result = $this->fromJson( $response );
        $error = $result['error'] ?? $result['message'] ?? $response->getReasonPhrase();

        if( is_array( $error ) ) {
            $error = $error['message'] ?? $response->getReasonPhrase();
        }

        $this->throw( $response->getStatusCode(), is_string( $error ) ? $error : $response->getReasonPhrase() );
    }
}
