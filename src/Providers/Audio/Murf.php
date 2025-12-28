<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Murf extends Base implements Revoice
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'api-key', $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.murf.ai' );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['channel_type', 'format', 'pitch', 'rate', 'sample_rate'] );

        $request = $this->request( ['voice_id' => $voice] + $allowed + ['format' => 'mp3'], ['file' => $audio] );
        $response = $this->client()->post( '/v1/voice-changer/convert', ['multipart' => $request] );

        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents() );

        return FileResponse::fromUrl( $data?->audio_file );
    }
}
