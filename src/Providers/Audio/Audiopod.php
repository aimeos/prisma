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


class Audiopod extends Base implements Speak, Transcribe
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

        $this->validate( $response );

        if( ( $data = json_decode( $response->getBody()->getContents() ) ) === null || !isset( $data->job_id ) ) {
            throw new PrismaException( 'Invalid response' );
        }

        $url = "api/v1/voice/tts-jobs/{$data->job_id}/status";
        return FileResponse::fromAsync( $this->closure( $url ), 5 );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, [
            'enable_confidence_scores', 'enable_speaker_diarization', 'enable_word_timestamps'
        ] );

        $files = [];
        $request = [
            'model_type' => $this->modelName( 'whisperx' ),
        ] + $allowed + ['enable_word_timestamps' => 0];

        if( $lang ) {
            $request['language'] = $lang;
        }

        if( $audio->url() ) {
            $url = 'api/v1/transcription/transcribe';
            $request['source_urls'] = [$audio->url()];
        } else {
            $url = 'api/v1/transcription/transcribe-upload';
            $files = ['files' => $audio];
        }

        $request = $this->request( $request, $files );
        $response = $this->client()->post( $url, ['multipart' => $request] );

        $this->validate( $response );

        if( ( $data = json_decode( $response->getBody()->getContents() ) ) === null || !isset( $data->job_id ) ) {
            throw new PrismaException( 'Invalid response' );
        }

        return TextResponse::fromAsync( $this->transcription( $data->job_id ), 5 );
    }


    protected function closure( string $url ) : \Closure
    {
        $client = $this->client();

        return function( FileResponse $fr ) use ( $client, $url ) : ?string {

            $response = $client->get( $url );

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


    protected function transcription( string $id ) : \Closure
    {
        $client = $this->client();

        return function( TextResponse $tr ) use ( $client, $id ) : ?string {


            $response = $client->get( "api/v1/transcription/jobs/{$id}" );

            if( $response->getStatusCode() !== 200 ) {
                throw new PrismaException( $response->getReasonPhrase() );
            }

            if( !( $data = json_decode( $response->getBody()->getContents() ) ) ) {
                throw new PrismaException( 'Invalid response: ' . $response->getBody()->getContents() );
            }

            if( @$data->status !== 'COMPLETED' ) {
                return null;
            }

            $response = $client->get( "api/v1/transcription/transcript/{$id}" );

            if( $response->getStatusCode() !== 200 ) {
                throw new PrismaException( $response->getReasonPhrase() );
            }

            if( !( $data = json_decode( $response->getBody()->getContents(), true ) ) ) {
                throw new PrismaException( 'Invalid response: ' . $response->getBody()->getContents() );
            }

            $text = join( ' ', array_map( function( $segment ) {
                return $segment['text'];
            }, $data['segments'] ?? [] ) );

            $tr->withStructured( $data['segments'] ?? [] )
                ->withMeta( $data['statistics'] ?? [] );

            return $text;
        };
    }
}
