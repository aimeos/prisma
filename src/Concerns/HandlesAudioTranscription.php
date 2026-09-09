<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


trait HandlesAudioTranscription
{
    /**
     * Transcribes audio and summarizes the transcript with a single chat completion request.
     *
     * @param string $endpoint Chat completion endpoint path
     * @param string $defaultModel Summary model to use when no model override is configured
     * @param Audio $audio Audio file to transcribe
     * @param string|null $lang ISO language code for transcription and summary, or null for the default
     * @param array<string, mixed> $options Options forwarded to the provider's transcription method
     * @return TextResponse Summary texts and usage reported by the chat completion
     */
    protected function describeAudio( string $endpoint, string $defaultModel, Audio $audio, ?string $lang, array $options ) : TextResponse
    {
        $text = $this->transcribe( $audio, $lang, $options )->text();
        $cmd = 'Summarize the text in a few words in plain text format in the language of ISO code "' . ( $lang ?? 'en' ) . '":';

        $request = [
            'model' => $this->modelName( $defaultModel ),
            'messages' => [
                ['role' => 'user', 'content' => $cmd . "\n" . $text]
            ]
        ];
        $response = $this->client()->post( $endpoint, ['json' => $request] );

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


    /**
     * Validates and decodes a plain-text or JSON transcription response.
     *
     * @param ResponseInterface $response Provider transcription response
     * @param array<string, mixed> $options Request options containing response_format, defaulting to json
     * @return TextResponse Transcript with segments and usage when returned as JSON
     */
    protected function transcriptionResponse( ResponseInterface $response, array $options ) : TextResponse
    {
        $this->validate( $response );

        /** @var string */
        $format = $options['response_format'] ?? 'json';

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
