<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Concerns\HandlesAudioTranscription;
use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Groq as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Groq extends Base implements Describe, Speak, Transcribe
{
    use HandlesAudioTranscription;


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->describeAudio( 'openai/v1/chat/completions', 'openai/gpt-oss-120b', $audio, $lang, $options );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'austin';
        $model = $this->modelName( 'canopylabs/orpheus-v1-english' );
        $allowed = $this->allowed( $options, ['response_format', 'sample_rate', 'speed'] );
        $allowed += ['response_format' => 'wav'];

        $request = ['model' => $model, 'input' => $text, 'voice' => $selected] + $allowed;
        $response = $this->client()->post( 'openai/v1/audio/speech', ['json' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/wav';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'whisper-large-v3-turbo' );
        $allowed = $this->allowed( $options, [
            'prompt', 'response_format', 'temperature', 'timestamp_granularities'
        ] ) + ['response_format' => 'verbose_json'];

        if( $lang ) {
            $allowed['language'] = $lang;
        }

        $request = $this->payload( ['model' => $model] + $allowed, ['file' => $audio] );
        $response = $this->client()->post( 'openai/v1/audio/transcriptions', ['multipart' => $request] );

        return $this->transcriptionResponse( $response, $allowed );
    }
}
