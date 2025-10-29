<?php

namespace Aimeos\Prisma\Files;


class Image extends File
{
    public function mimeType() : ?string
    {
        $mimetype = parent::mimeType( $mimeType );

        if( !strncmp( $mimeType, 'image/', 6) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type' ) );
        }

        return $mimetype;
    }


    public function setMimeType( ?string $mimeType ) : self
    {
        if( $mimeType && !strncmp( $mimeType, 'image/', 6) ) {
            throw new \InvalidArgumentException( sprintf( 'Must be an image mime type' ) );
        }

        return parent::setMimeType( $mimeType );
    }
}
