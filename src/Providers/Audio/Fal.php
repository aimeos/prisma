<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Compose;
use Aimeos\Prisma\Contracts\Audio\Demix;
use Aimeos\Prisma\Contracts\Audio\Denoise;
use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Synthesize;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Fal as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


/**
 * Audio models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/minimax-music/v2". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 * The text responses of describe() and transcribe() can't be resumed, describe() uses the OpenRouter
 * audio endpoint of fal and the "model" option selects the LLM (default: "google/gemini-3.8-flash").
 */
class Fal extends Base
    implements Compose, Demix, Denoise, Describe, Revoice, Speak, Synthesize, Transcribe
{
    protected const FILE = Audio::class;
    protected const MAX_BYTES = 100 * 1024 * 1024;


    public function compose( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['prompt' => $prompt];

        if( is_numeric( $options['duration'] ?? null ) ) {
            $input['music_length_ms'] = (int) round( $options['duration'] * 1000 );
        }

        return $this->submit( 'fal-ai/elevenlabs/music', $input, $options );
    }


    public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse
    {
        // Demucs separates into four or six stems, a single stem returns the vocals only
        $defaults = match( true ) {
            $stems <= 1 => ['model' => 'htdemucs', 'stems' => ['vocals']],
            $stems <= 4 => ['model' => 'htdemucs', 'stems' => ['vocals', 'drums', 'bass', 'other']],
            default => ['model' => 'htdemucs_6s', 'stems' => ['vocals', 'drums', 'bass', 'guitar', 'piano', 'other']],
        };

        return $this->submit( 'fal-ai/demucs', [
            'audio_url' => $this->uri( $audio ),
        ], array_replace( $defaults, $options ) );
    }


    public function denoise( Audio $audio, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/deepfilternet3', [
            'audio_url' => $this->uri( $audio ),
        ], $options );
    }


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->router( 'openrouter/router/audio', $this->summary( $lang ), ['audio_url' => $this->uri( $audio )], $options );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/elevenlabs/voice-changer', [
            'audio_url' => $this->uri( $audio ),
            'voice' => $voice,
        ], $options );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $input = ['text' => $text];

        if( $voice !== null && $voice !== '' ) {
            $input['voice'] = $voice;
        }

        return $this->submit( 'fal-ai/elevenlabs/tts/eleven-v3', $input, $options );
    }


    public function synthesize( string $prompt, array $options = [] ) : FileResponse
    {
        $input = ['text' => $prompt];

        if( is_numeric( $options['duration'] ?? null ) ) {
            $input['duration_seconds'] = (float) $options['duration'];
        }

        return $this->submit( 'fal-ai/elevenlabs/sound-effects/v2', $input, $options );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $input = ['audio_url' => $this->uri( $audio )];

        if( $lang !== null && $lang !== '' ) {
            $input['language_code'] = $lang;
        }

        $endpoint = $this->modelName( 'fal-ai/elevenlabs/speech-to-text/scribe-v2' );

        return $this->ask( $endpoint, array_replace( $options, $input ), function( array $data, TextResponse $response ) {

            if( !is_string( $data['text'] ?? null ) ) {
                throw new FailedException( 'No text found in fal response' );
            }

            // Whisper based models return "chunks" instead of "words"
            $parts = $data['words'] ?? $data['chunks'] ?? [];

            $response->add( $data['text'] )
                ->withStructured( is_array( $parts ) ? $parts : [] )
                ->withMeta( array_diff_key( $data, ['text' => 1, 'words' => 1, 'chunks' => 1] ) );
        } );
    }
}
