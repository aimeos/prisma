<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Murf extends Base implements Revoice, Speak
{
    private string $streamUrl;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.murf.ai' ) );
        $this->streamUrl = $this->config( $config, 'stream_url', 'https://global.api.murf.ai/v1/speech/stream' );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['channel_type', 'format', 'pitch', 'rate', 'sample_rate'] );

        $request = $this->payload( ['voice_id' => $voice] + $allowed + ['format' => 'mp3'], ['file' => $audio] );
        $response = $this->client()->post( '/v1/voice-changer/convert', ['multipart' => $request] );

        $this->validate( $response );

        $url = @$this->fromJson( $response )['audio_file'] ?? '';
        return FileResponse::fromUrl( is_string( $url ) ? $url : '' );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'en-US-natalie';
        $model = $this->modelName( 'falcon-2' );

        $allowed = $this->allowed( $options, [
            'channelType', 'format', 'locale', 'pitch', 'rate', 'sampleRate', 'style'
        ] );

        $request = ['voiceId' => $selected, 'text' => $text, 'model' => $model] + $allowed + ['format' => 'MP3'];
        $response = $this->client()->post( $this->streamUrl, ['json' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }
}
