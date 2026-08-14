<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Psr\Http\Message\ResponseInterface;


trait GeneratesVideo
{
    /**
     * Returns a public URL when available and otherwise an inline data URI.
     *
     * @param File $file Input media file
     * @return string Public URL or inline data URI
     */
    protected function mediaUrl( File $file ) : string
    {
        return $file->url() ?: 'data:' . ( $file->mimeType() ?? 'application/octet-stream' )
            . ';base64,' . $file->base64();
    }


    /**
     * Validates any successful 2xx response.
     *
     * @param ResponseInterface $response Provider response
     * @return void
     */
    protected function validateVideoResponse( ResponseInterface $response ) : void
    {
        $status = $response->getStatusCode();

        if( $status >= 200 && $status < 300 ) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $error */
        $error = is_array( $data['error'] ?? null ) ? $data['error'] : [];
        $message = $error['message'] ?? $data['message'] ?? $response->getReasonPhrase();

        $this->throw( $status, is_string( $message ) ? $message : '' );
    }


    /**
     * Raises a provider job failure with a consistent exception type.
     *
     * @param mixed $message Provider failure message
     * @return never
     */
    protected function videoFailed( mixed $message = null ) : never
    {
        throw new PrismaException( is_string( $message ) && $message !== ''
            ? $message
            : 'Video generation failed' );
    }
}
