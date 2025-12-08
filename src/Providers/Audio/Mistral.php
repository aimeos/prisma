<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Mistral extends Base implements Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.mistral.ai' );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, ['temperature', 'timestamp_granularities'] );

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

        $request = $this->request( $request, $files );
        $response = $this->client()->post( 'v1/audio/transcriptions', ['multipart' => $request] );

        return $this->toTextResponse( $response );
    }


    protected function toTextResponse( ResponseInterface $response ) : TextResponse
    {
        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];

        return TextResponse::fromText( $data['text'] ?? '' )
            ->withUsage( $data['usage']['total_tokens'] ?? 0, $data['usage'] ?? [] )
            ->withStructured( $data['segments'] ?? [] );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $this->throw( match( $status ) {
                422 => 400,
                default => $status
            }, json_decode( $response->getBody()->getContents() )?->message ?: $response->getReasonPhrase() );
        }
    }
}
