<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Elevenlabs extends Base implements Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'xi-api-key', $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.elevenlabs.io' );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, [
            'tag_audio_events', 'num_speakers', 'diarize', 'diarization_threshold',
            'file_format', 'temperature', 'seed', 'use_multi_channel', 'timestamps_granularity'
        ] );

        $files = [];
        $request = [
            'model_id' => $this->modelName( 'scribe_v1' ),
        ] + $allowed;

        if( $lang ) {
            $request['language_code'] = $lang;
        }

        if( $audio->url() ) {
            $request['cloud_storage_url'] = $audio->url();
        } else {
            $files = ['file' => $audio];
        }

        $request = $this->request( $request, $files );
        $response = $this->client()->post( 'v1/speech-to-text', ['multipart' => $request] );

        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];

        return TextResponse::fromText( $data['text'] ?? '' )
            ->withUsage( $data['usage']['total_tokens'] ?? 0, $data['usage'] ?? [] )
            ->withStructured( $data['words'] ?? [] );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->detail?->message ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
