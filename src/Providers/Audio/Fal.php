<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Concerns\FalApi;
use Aimeos\Prisma\Contracts\Audio\Compose;
use Aimeos\Prisma\Contracts\Audio\Demix;
use Aimeos\Prisma\Contracts\Audio\Denoise;
use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Synthesize;
use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


/**
 * Audio models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/minimax-music/v2". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 */
class Fal extends Base
    implements Cancel, Compose, Demix, Denoise, Resume, Revoice, Speak, Synthesize
{
    use FalApi;


    private const MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

    /** @var array<int, string> */
    private array $downloadHosts;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $hosts = $config['download_hosts'] ?? ['fal.media'];
        $this->downloadHosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : ['fal.media'];

        $this->header( 'Authorization', 'Key ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://queue.fal.run' ) );
    }


    public function compose( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['prompt' => $prompt];

        if( is_numeric( $options['duration'] ?? null ) ) {
            $input['music_length_ms'] = (int) round( $options['duration'] * 1000 );
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/elevenlabs/music' ), array_replace( $options, $input ) );
    }


    public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse
    {
        // Demucs separates into four or six stems, a single stem returns the vocals only
        $defaults = match( true ) {
            $stems <= 1 => ['model' => 'htdemucs', 'stems' => ['vocals']],
            $stems <= 4 => ['model' => 'htdemucs', 'stems' => ['vocals', 'drums', 'bass', 'other']],
            default => ['model' => 'htdemucs_6s', 'stems' => ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other']],
        };

        return $this->falQueue( (string) $this->modelName( 'fal-ai/demucs' ), array_replace( $defaults, $options, [
            'audio_url' => $this->falMedia( $audio ),
        ] ) );
    }


    public function denoise( Audio $audio, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/deepfilternet3' ), array_replace( $options, [
            'audio_url' => $this->falMedia( $audio ),
        ] ) );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/elevenlabs/voice-changer' ), array_replace( $options, [
            'audio_url' => $this->falMedia( $audio ),
            'voice' => $voice,
        ] ) );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $input = ['text' => $text];

        if( $voice !== null && $voice !== '' ) {
            $input['voice'] = $voice;
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/elevenlabs/tts/eleven-v3' ), array_replace( $options, $input ) );
    }


    public function synthesize( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['text' => $prompt];

        if( is_numeric( $options['duration'] ?? null ) ) {
            $input['duration_seconds'] = (float) $options['duration'];
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/elevenlabs/sound-effects/v2' ), array_replace( $options, $input ) );
    }


    /**
     * Builds a lazy audio file whose eventual download stays on the fal CDN.
     *
     * @param string $url Audio URL returned by fal
     * @param string|null $mimeType Content type of the audio if known
     * @return Audio Audio file
     */
    protected function falFile( string $url, ?string $mimeType ) : Audio
    {
        $audio = Audio::fromUrl( $url, $mimeType );
        $audio->restrictHosts( $this->downloadHosts );
        $audio->maxSize( self::MAX_DOWNLOAD_BYTES );

        return $audio;
    }
}
