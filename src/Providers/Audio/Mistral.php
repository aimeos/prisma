<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Mistral as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Mistral extends Base implements Describe, Transcribe
{
    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $text = $this->transcribe( $audio, $lang, $options )->text();
        $cmd = 'Summarize the text in a few words in plain text format in the language of ISO code "' . ( $lang ?? 'en' ) . '":';

        $request = [
            'model' => $this->modelName( 'mistral-large-latest' ),
            'messages' => [
                ['role' => 'user', 'content' => $cmd . "\n" . $text]
            ]
        ];
        $response = $this->client()->post( 'v1/chat/completions', ['json' => $request] );

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


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, ['temperature', 'timestamp_granularities', 'diarize'] );

        $files = [];
        $request = [
            'language' => $lang,
            'model' => $this->modelName( 'voxtral-mini-latest' ),
        ] + $allowed + ['timestamp_granularities' => 'segment'];

        if( $audio->url() ) {
            $request['file_url'] = $audio->url();
        } else {
            $files['file'] = $audio;
        }

        $request = $this->payload( $request, $files );
        $response = $this->client()->post( 'v1/audio/transcriptions', ['multipart' => $request] );

        return $this->toTextResponse( $response );
    }


    protected function toTextResponse( ResponseInterface $response ) : TextResponse
    {
        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        $text = $data['text'] ?? '';

        /** @var array<string, mixed> */
        $usage = $data['usage'] ?? [];
        $totalTokens = $usage['total_tokens'] ?? 0;

        /** @var array<int, array<string, mixed>> */
        $segments = $data['segments'] ?? [];

        return TextResponse::fromText( is_string( $text ) ? $text : '' )
            ->withUsage( is_numeric( $totalTokens ) ? (float) $totalTokens : 0, $usage )
            ->withStructured( $segments );
    }
}
