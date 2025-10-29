<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\Background;
use Aimeos\Prisma\Concerns\Detext;
use Aimeos\Prisma\Concerns\Erase;
use Aimeos\Prisma\Concerns\Studio;
use Aimeos\Prisma\Concerns\Uncrop;
use Aimeos\Prisma\Concerns\Upscale;
use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Clipdrop
    extends Base
    implements Background, Detext, Erase, Studio, Uncrop, Upscale
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', (string) $config['api_key'] );
        $this->baseUrl( 'https://clipdrop-api.co' );
    }


    public function background( Image $image, ?string $prompt = null, array $options = [] ) : FileResponse
    {
        $data = [];
        $url = 'remove-background/v1';

        if( $prompt )
        {
            $data = ['prompt' => $prompt];
            $url = 'replace-background/v1';
        }

        $request = $this->request( $data + $options, ['image_file' => $image] );
        $response = $this->client()->post( $url, $request );

        return $this->toResponse( $response );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        $request = $this->request( $options, ['image_file' => $image] );
        $response = $this->client()->post( 'remove-text/v1', $request );

        return $this->toResponse( $response );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        $request = $this->request( $options, ['image_file' => $image, 'mask_file' => $mask] );
        $response = $this->client()->post( 'cleanup/v1', $request );

        return $this->toResponse( $response );
    }


    public function image( string $prompt, array $options = [] ) : FileResponse
    {
        $request = $this->request( ['prompt' => $prompt] + $options );
        $response = $this->client()->post( 'text-to-image/v1', $request );

        return $this->toResponse( $response );
    }


    public function studio( Image $image, array $options = [] ) : FileResponse
    {
        $request = $this->request( $options, ['image_file' => $image] );
        $response = $this->client()->post( 'product-photography/v1', $request );

        return $this->toResponse( $response );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        $data = [
            'extend_up' => min( $top, 2048 ),
            'extend_down' => min( $bottom, 2048 ),
            'extend_left' => min( $left, 2048 ),
            'extend_right' => min( $right, 2048 ),
        ];

        $request = $this->request( $data + $options, ['image_file' => $image] );
        $response = $this->client()->post( 'uncrop/v1', $request );

        return $this->toResponse( $response );
    }


    public function upscale( Image $image, int $width, int $height ) : FileResponse
    {
        $data = [
            'target_width' => min( $width, 4096 ),
            'target_height' => min( $height, 4096 ),
        ];

        $request = $this->request( $data + $options, ['image_file' => $image] );
        $response = $this->client()->post( 'image-upscaling/v1/upscale', $request );

        return $this->toResponse( $response );
    }


    protected function toResponse( ResponseInterface $response ) : FileResponse
    {
        $mimeType = $response->getHeader( 'Content-Type' )[0] ?? null;

        return FileResponse::fromBinary( $response->getBody(), $mimeType )
            ->withUsage(
                $response->getHeader( 'x-credits-consumed' )[0] ?? null,
                $response->getHeader( 'x-remaining-credits' )[0] ?? null
            );
    }
}
