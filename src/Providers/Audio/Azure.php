<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Concerns\HandlesAudioTranscription;
use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Azure as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Azure extends Base implements Describe, Speak, Transcribe
{
    use HandlesAudioTranscription;


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->describeAudio( 'openai/v1/chat/completions', 'gpt-5.6-luna', $audio, $lang, $options );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'alloy';
        $model = $this->modelName( 'gpt-4o-mini-tts' );
        $allowed = $this->allowed( $options, ['instructions', 'response_format', 'speed'] );

        $request = ['model' => $model, 'input' => $text, 'voice' => $selected] + $allowed;
        $response = $this->client()->post( 'openai/v1/audio/speech', ['json' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = (string) $this->modelName( 'gpt-4o-transcribe' );
        $format = str_starts_with( $model, 'whisper' ) ? 'verbose_json' : 'json';

        $allowed = $this->allowed( $options, [
            'chunking_strategy', 'include', 'prompt', 'response_format', 'temperature', 'timestamp_granularities'
        ] ) + ['response_format' => $format];

        if( $lang ) {
            $allowed['language'] = $lang;
        }

        $request = $this->payload( ['model' => $model] + $allowed, ['file' => $audio] );
        $response = $this->client()->post( 'openai/v1/audio/transcriptions', ['multipart' => $request] );

        return $this->transcriptionResponse( $response, $allowed );
    }
}
