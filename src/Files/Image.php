<?php

namespace Aimeos\Prisma\Files;


/**
 * Image file content.
 */
class Image extends File
{
    /**
     * Returns the mime type.
     *
     * @return string|null Mime type
     */
    public function mimeType() : ?string
    {
        $mimeType = parent::mimeType();

        if( !str_starts_with( $mimeType, 'image/' ) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type, got "%1$s"', $mimeType ) );
        }

        return $mimeType;
    }


    /**
     * Sets the mime type.
     *
     * @param string|null $mimeType Mime type
     * @return self File instance
     */
    public function withMimeType( ?string $mimeType ) : self
    {
        if( $mimeType && !str_starts_with( $mimeType, 'image/' ) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type, got "%1$s"', $mimeType ) );
        }

        return parent::withMimeType( $mimeType );
    }
}
