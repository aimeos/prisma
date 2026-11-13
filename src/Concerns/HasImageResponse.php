<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


trait HasImageResponse
{
    /**
     * Converts the Guzzle response into a file response.
     *
     * @param ResponseInterface $response Guzzle HTTP response
     * @return FileResponse File based response
     */
    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        $files = $this->fileResponse();

        /** @var array<int, array<string, mixed>> $dataItems */
        $dataItems = $result['data'] ?? [];

        foreach( $dataItems as $item )
        {
            if( is_string( $item['b64_json'] ?? null ) && $item['b64_json'] !== '' ) {
                $files->add( Image::fromBase64( $item['b64_json'] ) );
            } elseif( is_string( $item['url'] ?? null ) && $item['url'] !== '' ) {
                $files->addUrl( $item['url'], Image::class );
            }
        }

        if( $files->empty() ) {
            throw new PrismaException( 'No image data found in response' );
        }

        $meta = $result;
        unset( $meta['data'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];
        $used = $usage['total_tokens'] ?? null;

        return $files->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )->withMeta( $meta );
    }
}
