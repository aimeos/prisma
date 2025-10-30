<?php

namespace Aimeos\Prisma\Files;


class Image extends File
{
    public function mimeType() : ?string
    {
        $mimeType = parent::mimeType();

        if( strncmp( $mimeType, 'image/', 6 ) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type, got "%1$s"', $mimeType ) );
        }

        return $mimeType;
    }


    public function setMimeType( ?string $mimeType ) : self
    {
        if( $mimeType && strncmp( $mimeType, 'image/', 6 ) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type, got "%1$s"', $mimeType ) );
        }

        return parent::setMimeType( $mimeType );
    }
}
