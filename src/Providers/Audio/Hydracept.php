<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Hydracept as Base;


class Hydracept extends Base implements Speak
{
    private const VOICE = 'audio.voice.generate.v1';
    private const SFX = 'audio.sfx.generate.v1';
    private const MUSIC = 'audio.music.generate.v1';


    public function speak( string $text, ?string $voice = null, array $options = [] ) : \Aimeos\Prisma\Responses\FileResponse
    {
        if( $voice === null || $voice === '' ) {
            throw new BadRequestException( 'Voice ID required for Hydracept speech synthesis' );
        }

        $input = ['text' => $text, 'voiceId' => $voice] + $this->capabilityOptions( $options, [
            'modelId', 'variantCount', 'diversityMode', 'seed', 'outputFormatPreference',
            'voiceSettings', 'providerOptions',
        ] );

        if( $model = $this->modelName() ) {
            $input['modelId'] = $model;
        }

        return $this->generate( self::VOICE, $input, $options );
    }


    /**
     * Generates sound effects from a text prompt.
     *
     * Hydracept capability `audio.sfx.generate.v1`; not part of the shared Speak contract.
     *
     * @param string $prompt Effect description
     * @param array<string, mixed> $options Capability and envelope options
     */
    public function sfx( string $prompt, array $options = [] ) : \Aimeos\Prisma\Responses\FileResponse
    {
        $input = ['prompt' => $prompt] + $this->capabilityOptions( $options, [
            'durationMs', 'variantCount', 'diversityMode', 'promptInfluence', 'modelId',
            'outputFormatPreference', 'providerOptions',
        ] );

        if( $model = $this->modelName() ) {
            $input['modelId'] = $model;
        }

        return $this->generate( self::SFX, $input, $options );
    }


    /**
     * Generates music from a text prompt.
     *
     * Hydracept capability `audio.music.generate.v1`; not part of the shared Speak contract.
     *
     * @param string $prompt Music description
     * @param array<string, mixed> $options Capability and envelope options
     */
    public function music( string $prompt, array $options = [] ) : \Aimeos\Prisma\Responses\FileResponse
    {
        $input = ['prompt' => $prompt] + $this->capabilityOptions( $options, [
            'durationMs', 'variantCount', 'forceInstrumental', 'modelId',
            'outputFormatPreference', 'providerOptions',
        ] );

        if( $model = $this->modelName() ) {
            $input['modelId'] = $model;
        }

        return $this->generate( self::MUSIC, $input, $options );
    }


    protected function artifactFactory() : callable
    {
        return fn( string $binary, string $mediaType ) : File => Audio::fromBinary( $binary, $mediaType );
    }
}
