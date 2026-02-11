<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Audiopod extends Base implements Speak
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'X-API-Key', $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api.audiopod.ai' );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['audio_format', 'language', 'speed'] );
        $selected = $voice ?: 'b76f1226-8170-4902-9482-36bb4fc98085'; // fallback: aura

        $request = $this->request( ['input_text' => $text] + $allowed + ['audio_format' => 'mp3'] );
        $response = $this->client()->post( "api/v1/voice/voices/{$selected}/generate", ['multipart' => $request] );

        return $this->toFileResponse( $response );
    }


    protected function closure( string $jobid ) : \Closure
    {
        $client = $this->client();

        return function() use ( $client, $jobid ) {

            $response = $client->get( "api/v1/voice/tts-jobs/{$jobid}/status" );

            if( $response->getStatusCode() !== 200 ) {
                throw new PrismaException( $response->getReasonPhrase() );
            }

            if( !( $data = json_decode( $response->getBody()->getContents() ) ) ) {
                throw new PrismaException( 'Invalid response: ' . $response->getBody()->getContents() );
            }

            if( @$data->status !== 'COMPLETED' ) {
                return null;
            }

            if( !@$data->output_url ) {
                throw new PrismaException( 'Invalid response: ' . $response->getBody()->getContents() );
            }

            $response = $client->get( $data->output_url );

            if( $response->getStatusCode() !== 200 ) {
                throw new PrismaException( $response->getReasonPhrase() );
            }

            return $response->getBody()->getContents();
        };
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        if( ( $data = json_decode( $response->getBody()->getContents() ) ) === null || !isset( $data->job_id ) ) {
            throw new PrismaException( 'Invalid response' );
        }

        return FileResponse::fromAsync( $this->closure( $data->job_id ), 2 );
    }
}
