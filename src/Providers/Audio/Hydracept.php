<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Compose;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Synthesize;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Hydracept as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Hydracept extends Base implements Compose, Speak, Synthesize
{
    protected const PREFIX = 'audio/';

    private const MUSIC = 'audio.music.generate.v1';
    private const SFX = 'audio.sfx.generate.v1';
    private const VOICE = 'audio.voice.generate.v1';


    public function compose( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['prompt' => $prompt] + $this->allowed( $options, [
            'durationMs', 'variantCount', 'forceInstrumental', 'modelId',
            'outputFormatPreference', 'providerExtensions',
        ] ) + $this->duration( $options );

        return $this->audio( self::MUSIC, $input, $options );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        if( $voice === null || $voice === '' ) {
            throw new BadRequestException( 'Voice ID required for Hydracept speech synthesis' );
        }

        $input = ['text' => $text, 'voiceId' => $voice] + $this->allowed( $options, [
            'modelId', 'variantCount', 'diversityMode', 'seed', 'outputFormatPreference',
            'voiceSettings', 'providerExtensions',
        ] );

        return $this->audio( self::VOICE, $input, $options );
    }


    public function synthesize( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['prompt' => $prompt] + $this->allowed( $options, [
            'durationMs', 'variantCount', 'diversityMode', 'promptInfluence', 'modelId',
            'outputFormatPreference', 'providerExtensions',
        ] ) + $this->duration( $options );

        return $this->audio( self::SFX, $input, $options );
    }


    protected function toFile( string $binary, ?string $mediaType ) : File
    {
        return Audio::fromBinary( $binary, $mediaType );
    }


    /**
     * Generates audio using the model set by model() if any.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options
     * @return FileResponse Asynchronous file response
     */
    private function audio( string $capability, array $input, array $options ) : FileResponse
    {
        if( $model = $this->modelName() ) {
            $input['modelId'] = $model;
        }

        return $this->generate( $capability, $input, $options );
    }


    /**
     * Maps the common duration option in seconds to the Hydracept duration in milliseconds.
     *
     * @param array<string, mixed> $options Caller options
     * @return array<string, int> Duration input field or empty if not passed
     */
    private function duration( array $options ) : array
    {
        $duration = $options['duration'] ?? null;

        return is_numeric( $duration ) ? ['durationMs' => (int) round( $duration * 1000 )] : [];
    }
}
