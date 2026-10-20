<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Hydracept as Base;


class Hydracept extends Base implements Imagine
{
    private const CAPABILITY = 'image.generate.v1';


    public function imagine( string $prompt, array $images = [], array $options = [] ) : \Aimeos\Prisma\Responses\FileResponse
    {
        $input = ['prompt' => $prompt] + $this->capabilityOptions( $options, [
            'width', 'height', 'quality', 'variantCount', 'requestTransparentOutput',
            'transparencyMethod', 'transparentRegions', 'keyColor', 'sheet', 'adapters',
            'providerExtensions',
        ] );

        if( $model = $this->modelName() ) {
            $input['model'] = $model;
        }

        if( $references = $this->referenceImages( $images ) ) {
            $input['referenceImages'] = $references;
        }

        return $this->generate( self::CAPABILITY, $input, $options );
    }


    protected function artifactFactory() : callable
    {
        return fn( string $binary, string $mediaType ) : File => Image::fromBinary( $binary, $mediaType );
    }
}
