<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Openrouter extends Base implements Describe, Speak, Transcribe
{
    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning', 'provider'] );

        return $this->completions(
            'api/v1/chat/completions', 'google/gemini-3.7-flash',
            $this->messages( $this->content( $prompt, [$audio] ) ),
            $options
        );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $options = $this->allowed( $options, ['input_references', 'provider', 'response_format', 'speed'] );
        $options += ['response_format' => 'mp3'];
        $request = [
            'model' => $this->modelName( 'hexgrad/kokoro-82m' ),
            'input' => $text,
            'voice' => $voice ?: 'af_alloy',
        ] + $options;
        $response = $this->client()->post( 'api/v1/audio/speech', ['json' => $request] );

        $this->validate( $response );

        $mimeType = $response->getHeaderLine( 'Content-Type' ) ?: match( $options['response_format'] ) {
            'pcm' => 'audio/pcm',
            default => 'audio/mpeg',
        };

        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimeType )
            ->withMeta( ['generation_id' => $response->getHeaderLine( 'X-Generation-Id' )] );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, [
            'provider', 'response_format', 'temperature', 'timestamp_granularities'
        ] );

        if( $lang ) {
            $options['language'] = $lang;
        }

        $request = [
            'model' => $this->modelName( 'openai/whisper-large-v3' ),
            'input_audio' => [
                'data' => $audio->base64(),
                'format' => $this->audioFormat( $audio ),
            ],
        ] + $options;
        $response = $this->client()->post( 'api/v1/audio/transcriptions', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $usage */
        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
        /** @var array<int, array<string, mixed>> $segments */
        $segments = is_array( $data['segments'] ?? null ) ? $data['segments'] : [];
        $used = $usage['total_tokens'] ?? $usage['seconds'] ?? null;
        $text = $data['text'] ?? null;

        $meta = $data;
        unset( $meta['text'], $meta['segments'], $meta['usage'] );

        return TextResponse::fromText( is_string( $text ) ? $text : null )
            ->withStructured( $segments )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
            ->withMeta( $meta );
    }
}
