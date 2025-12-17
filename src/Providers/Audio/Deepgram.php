<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Deepgram extends Base implements Speak
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Token ' . $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.deepgram.com' );
    }


    public function speak( string $text, array $voice = [], array $options = [] ) : FileResponse
    {
        $voice = array_filter( $voice, fn( $name ) => str_starts_with( $name, 'aura-' ) );
        $selected = current( $voice ) ?: 'aura-asteria-en';

        $params = ['model' => $selected] + $this->allowed( $options, [
            'callback', 'callback_method', 'mip_opt_out', 'tag', 'bit_rate', 'container', 'encoding', 'sample_rate'
        ] );

        $response = $this->client()->post( '/v1/speak?' . http_build_query( $params ), ['json' => ['text' => $text]] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->err_msg ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
