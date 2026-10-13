<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Concerns\HandlesAudioTranscription;
use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Meta as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Meta extends Base implements Describe, Transcribe
{
    use HandlesAudioTranscription;


    /**
     * Languages supported by Muse Voice Transcribe, mapped from their ISO codes.
     */
    private const LANGUAGES = [
        'ar' => 'Arabic', 'bn' => 'Bengali', 'de' => 'German', 'en' => 'English', 'es' => 'Spanish',
        'fr' => 'French', 'he' => 'Hebrew', 'hi' => 'Hindi', 'id' => 'Indonesian', 'it' => 'Italian',
        'ja' => 'Japanese', 'kn' => 'Kannada', 'ko' => 'Korean', 'mr' => 'Marathi', 'ms' => 'Malay',
        'nl' => 'Dutch', 'pl' => 'Polish', 'pt' => 'Portuguese', 'ta' => 'Tamil', 'te' => 'Telugu',
        'th' => 'Thai', 'tl' => 'Tagalog', 'tr' => 'Turkish', 'vi' => 'Vietnamese', 'zh' => 'Mandarin Chinese',
    ];


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->describeAudio( 'v1/chat/completions', 'muse-spark-1.3', $audio, $lang, $options );
    }


    /**
     * Transcribes a mono 16-bit PCM WAV recording (16 or 24 kHz, max. 10 minutes).
     *
     * @param Audio $audio WAV audio file
     * @param string|null $lang ISO language code or language name to bias the transcription to
     * @param array<string, mixed> $options Provider-specific options
     * @return TextResponse Transcript with the detected turns as structured data
     */
    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $request = [
            'model' => $this->modelName( 'muse-voice-transcribe-1.0' ),
            'audioEncoding' => 'WAV',
        ] + $this->allowed( $options, ['keywords', 'mode'] );

        if( $lang ) {
            $request['languageBias'] = [self::LANGUAGES[strtolower( $lang )] ?? $lang];
        }

        $payload = $this->payload( [], ['audio' => $audio] );
        $payload[] = ['name' => 'request', 'contents' => json_encode( $request ), 'headers' => ['Content-Type' => 'application/json']];

        $response = $this->client()->post( 'v1/asr/transcribe', [
            'headers' => ['Accept' => 'application/json'],
            'multipart' => $payload,
        ] );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> */
        $turns = $data['turns'] ?? [];
        $transcript = $data['transcript'] ?? '';
        $duration = $data['audioDurationMs'] ?? null;

        return TextResponse::fromText( is_string( $transcript ) ? $transcript : '' )
            ->withStructured( $turns )
            ->withUsage( is_numeric( $duration ) ? floor( $duration / 1000 ) : null, ['audioDurationMs' => $duration] )
            ->withMeta( ['sessionId' => $data['sessionId'] ?? null] );
    }
}
