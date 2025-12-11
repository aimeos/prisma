<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Groq extends Base implements Speak, Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.groq.com' );
    }


    public function speak( string $text, array $voice = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'playai-tts' );
        $allowed = $this->allowed( $options, ['response_format', 'sample_rate', 'speed'] );

        $selected = current( $voice ) ?: 'Basil-PlayAI';

        $request = ['model' => $model, 'input' => $text, 'voice' => $selected] + $allowed;
        $response = $this->client()->post( 'openai/v1/audio/speech', ['json' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
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


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->error?->message ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
