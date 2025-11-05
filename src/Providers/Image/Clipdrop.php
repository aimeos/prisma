<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Detext;
use Aimeos\Prisma\Contracts\Image\Erase;
use Aimeos\Prisma\Contracts\Image\Image;
use Aimeos\Prisma\Contracts\Image\Studio;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Files\Image as ImageFile;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Clipdrop extends Base
    implements Background, Isolate, Detext, Erase, Image, Studio, Uncrop, Upscale
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', (string) $config['api_key'] );
        $this->baseUrl( 'https://clipdrop-api.co' );
    }


    public function background( ImageFile $image, string $prompt, array $options = [] ) : FileResponse
    {
        $request = $this->request( ['prompt' => $prompt], ['image_file' => $image] );
        $response = $this->client()->post( 'replace-background/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function detext( ImageFile $image, array $options = [] ) : FileResponse
    {
        $request = $this->request( $options, ['image_file' => $image] );
        $response = $this->client()->post( 'remove-text/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function erase( ImageFile $image, ImageFile $mask, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['mode'] );
        $request = $this->request( $allowed, ['image_file' => $image, 'mask_file' => $mask] );
        $response = $this->client()->post( 'cleanup/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function image( string $prompt, array $options = [] ) : FileResponse
    {
        $request = $this->request( ['prompt' => $prompt] );
        $response = $this->client()->post( 'text-to-image/v1', $request );

        return $this->toFileResponse( $response );
    }


    public function isolate( ImageFile $image, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['transparency_handling'] );
        $request = $this->request( $allowed, ['image_file' => $image] );
        $response = $this->client()->post( 'remove-background/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function studio( ImageFile $image, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['background_color_choice', 'light_theta', 'light_phi', 'light_size', 'shadow_darkness'] );
        $request = $this->request( $options, ['image_file' => $image] );
        $response = $this->client()->post( 'product-photography/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function uncrop( ImageFile $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        $data = [
            'extend_up' => min( $top, 2048 ),
            'extend_down' => min( $bottom, 2048 ),
            'extend_left' => min( $left, 2048 ),
            'extend_right' => min( $right, 2048 ),
        ];

        $allowed = $this->allowed( $options, ['seed'] );
        $request = $this->request( $data + $allowed, ['image_file' => $image] );
        $response = $this->client()->post( 'uncrop/v1', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    public function upscale( ImageFile $image, int $width, int $height, array $options = [] ) : FileResponse
    {
        $data = [
            'target_width' => min( $width, 4096 ),
            'target_height' => min( $height, 4096 ),
        ];

        $request = $this->request( $data, ['image_file' => $image] );
        $response = $this->client()->post( 'image-upscaling/v1/upscale', ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        switch( $response->getStatusCode() )
        {
            case 200: break;
            case 400:
            case 406: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $response->getReasonPhrase() );
            case 401:
            case 403: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $response->getReasonPhrase() );
            case 402: throw new \Aimeos\Prisma\Exceptions\PaymentRequiredException( $response->getReasonPhrase() );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $response->getReasonPhrase() );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $response->getReasonPhrase() );
        }

        $mimeType = $response->getHeaderLine( 'Content-Type' );

        return FileResponse::fromBinary( $response->getBody(), $mimeType )->withUsage(
            $response->getHeaderLine( 'x-credits-consumed' ), [
                'x-remaining-credits' => $response->getHeaderLine( 'x-remaining-credits' )
            ]
        );
    }
}
