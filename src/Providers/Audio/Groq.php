<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Groq extends Base implements Speak
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


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->error?->message ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
