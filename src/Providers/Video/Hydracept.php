<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Hydracept as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Hydracept extends Base implements Imagine
{
    protected const PREFIX = 'video/';

    private const CAPABILITY = 'video.generate.v1';


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $input = ['prompt' => $prompt] + $this->allowed( $options, [
            'aspectRatio', 'durationSeconds', 'features',
        ] );

        $input += is_numeric( $options['duration'] ?? null ) ? ['durationSeconds' => (int) round( (float) $options['duration'] )] : [];

        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];
        $images = array_filter( $references, fn( mixed $file ) : bool => $file instanceof Image );

        if( $encoded = $this->referenceImages( $images ) ) {
            $input['referenceImages'] = $encoded;
        }

        return $this->generate( self::CAPABILITY, $input, $options );
    }


    protected function toFile( string $binary, ?string $mediaType ) : File
    {
        return Video::fromBinary( $binary, $mediaType );
    }
}
