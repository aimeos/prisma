<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Groq as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


class Groq extends Base implements Describe, Speak, Transcribe
{
    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $text = $this->transcribe( $audio, $lang, $options )->text();
        $cmd = 'Summarize the text in a few words in plain text format in the language of ISO code "' . ( $lang ?? 'en' ) . '":';

        $request = [
            'model' => $this->modelName( 'openai/gpt-oss-120b' ),
            'messages' => [
                ['role' => 'user', 'content' => $cmd . "\n" . $text]
            ]
        ];
        $response = $this->client()->post( 'openai/v1/chat/completions', ['json' => $request] );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> */
        $choices = $data['choices'] ?? [];

        /** @var array<string, mixed> */
        $usage = $data['usage'] ?? [];

        /** @var list<string|null> */
        $texts = [];

        foreach( $choices as $choice ) {
            /** @var array<string, mixed> */
            $message = $choice['message'] ?? [];
            $content = $message['content'] ?? '';
            $texts[] = is_string( $content ) ? $content : '';
        }

        $totalTokens = $usage['total_tokens'] ?? null;

        return TextResponse::fromTexts( $texts )
            ->withUsage(
                is_numeric( $totalTokens ) ? (float) $totalTokens : null,
                $usage,
            );
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
        $model = $this->modelName( 'whisper-large-v3' );
        $allowed = $this->allowed( $options, [
            'prompt', 'response_format', 'temperature', 'timestamp_granularities'
        ] ) + ['response_format' => 'verbose_json'];

        if( $lang ) {
            $allowed['language'] = $lang;
        }

        $request = $this->request( ['model' => $model] + $allowed, ['file' => $audio] );
        $response = $this->client()->post( 'openai/v1/audio/transcriptions', ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $format = $allowed['response_format'] ?? 'json';

        if( !str_contains( $format, 'json' ) ) {
            return TextResponse::fromText( $response->getBody()->getContents() );
        }

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        $text = $data['text'] ?? null;

        /** @var array<int, array<string, mixed>> */
        $segments = $data['segments'] ?? [];

        /** @var array<string, mixed> */
        $usage = $data['usage'] ?? [];
        $totalTokens = $usage['total_tokens'] ?? null;

        return TextResponse::fromText( is_string( $text ) ? $text : null )
            ->withStructured( $segments )
            ->withUsage(
                is_numeric( $totalTokens ) ? (float) $totalTokens : null,
                $usage,
            );
    }
}
