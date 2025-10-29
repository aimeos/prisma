<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Files\Image;


class Clipdrop
    extends Base
    implements Background
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

        $request = $this->request( $data, ['image_file' => $image] );
        $response = $this->client()->post( $url, $request );
        $mimeType = $response->getHeader( 'Content-Type' )[0] ?? null;

        return FileResponse::fromBinary( $response->getBody(), $mimeType )
            ->withUsage(
                $response->getHeader( 'x-credits-consumed' )[0] ?? null,
                $response->getHeader( 'x-remaining-credits' )[0] ?? null
            );
    }
}
