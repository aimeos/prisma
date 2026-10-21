<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Hydracept as Base;


class Hydracept extends Base implements Imagine
{
    use GeneratesVideo;

    private const CAPABILITY = 'video.generate.v1';


    public function imagine( string $prompt, array $media = [], array $options = [] ) : \Aimeos\Prisma\Responses\FileResponse
    {
        $input = ['prompt' => $prompt] + $this->capabilityOptions( $options, [
            'durationSeconds', 'aspectRatio', 'features', 'providerOptions',
        ] );

        if( isset( $options['duration'] ) && !isset( $input['durationSeconds'] ) ) {
            $input['durationSeconds'] = $options['duration'];
        }

        if( isset( $options['aspectRatio'] ) && !isset( $input['aspectRatio'] ) ) {
            $input['aspectRatio'] = $options['aspectRatio'];
        }

        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];
        $images = array_values( array_filter( $references, fn( mixed $file ) : bool => $file instanceof Image ) );

        if( $encoded = $this->referenceImages( $images ) ) {
            $input['referenceImages'] = $encoded;
        }

        return $this->generate( self::CAPABILITY, $input, $options );
    }


    protected function artifactFactory() : callable
    {
        return fn( string $binary, string $mediaType ) : File => Video::fromBinary( $binary, $mediaType );
    }
}
