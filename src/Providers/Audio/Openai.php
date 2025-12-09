<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Openai as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Openai extends Base implements Transcribe
{
    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'whisper-1' );
        $format = $model === 'whisper-1' ? 'verbose_json' : 'json';

        $allowed = $this->allowed( $options, [
            'chunking_strategy', 'include', 'known_speaker_names', 'known_speaker_references',
            'prompt', 'response_format', 'temperature', 'timestamp_granularities'
        ] ) + ['response_format' => $format];

        if( $lang ) {
            $allowed['language'] = $lang;
        }

        $request = $this->request( ['model' => $model] + $allowed, ['file' => $audio] );
        $response = $this->client()->post( '/v1/audio/transcriptions', ['multipart' => $request] );

        $this->validate( $response );

        if( !str_contains( $allowed['response_format'] ?? 'json', 'json' ) ) {
            return TextResponse::fromText( $response->getBody()->getContents() );
        }

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];

        return TextResponse::fromText( $data['text'] ?? null )
            ->withStructured( $data['segments'] ?? [] )
            ->withUsage(
                $data['usage']['total_tokens'] ?? null,
                $data['usage'] ?? [],
            );
    }
}
