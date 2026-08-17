<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Psr\Http\Message\ResponseInterface;


trait GeneratesVideo
{
    /**
     * Normalizes uncrop edge expansions and rejects invalid no-op requests.
     *
     * @param float $top Fraction of the source height to add at the top
     * @param float $right Fraction of the source width to add at the right
     * @param float $bottom Fraction of the source height to add at the bottom
     * @param float $left Fraction of the source width to add at the left
     * @return array{top: float, right: float, bottom: float, left: float} Normalized edge expansions
     * @throws BadRequestException If an expansion is not finite or all expansions are zero
     */
    protected function uncropEdges( float $top, float $right, float $bottom, float $left ) : array
    {
        $edges = ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left];

        foreach( $edges as $value ) {
            if( !is_finite( $value ) ) {
                throw new BadRequestException( 'Video frame expansion values must be finite' );
            }
        }

        $edges = array_map( fn( float $value ) : float => max( 0, min( 1, $value ) ), $edges );

        if( max( $edges ) === 0.0 ) {
            throw new BadRequestException( 'At least one video frame expansion must be greater than zero' );
        }

        return $edges;
    }


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
