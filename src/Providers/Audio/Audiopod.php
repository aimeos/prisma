<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Demix;
use Aimeos\Prisma\Contracts\Audio\Denoise;
use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Audiopod extends Base implements Demix, Denoise, Revoice, Speak, Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'X-API-Key', $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.audiopod.ai' ) );
    }


    public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse
    {
        foreach( [1, 2, 4, 6, 8, 12, 16] as $num ) {
            if( $stems <= $num ) {
                $stems = $num;
                break;
            }
        }

        $mode = match( $stems ) {
            2 => 'two',
            4 => 'four',
            6 => 'six',
            8 => 'producer',
            12 => 'studio',
            16 => 'mastering',
            default => 'single'
        };

        $params = ['mode' => $mode];

        if( $stems === 1 ) {
            $params['stem'] = 'vocals';
        }

        if( $audio->url() ) {
            $request = $this->request( ['url' => $audio->url()] + $params );
        } else {
            $request = $this->request( $params, ['file' => $audio] );
        }

        $response = $this->client()->post( "api/v1/stem-extraction/api/extract", ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        $url = "api/v1/stem-extraction/status/" . $id;
        return FileResponse::fromAsync( $this->download( $url, 'download_urls' ), 3 );
    }


    public function denoise( Audio $audio, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['quality_mode'] ) + ['quality_mode' => 'balanced'];

        if( $audio->url() ) {
            $request = ['json' => $this->request( ['url' => $audio->url()] + $allowed )];
        } else {
            $request = ['multipart' => $this->request( $allowed, ['file' => $audio] )];
        }

        $response = $this->client()->post( "api/v1/denoiser/denoise", $request );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        $url = "api/v1/denoiser/jobs/" . $id;
        return FileResponse::fromAsync( $this->download( $url, 'output_url' ), 3 );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        $request = $this->request( ['voice_uuid' => $voice], ['file' => $audio] );
        $response = $this->client()->post( 'api/v1/voice/voice-convert', ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        $url = "api/v1/voice/convert/" . $id . "/status";
        return FileResponse::fromAsync( $this->revoiced( $url ), 3 );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['audio_format', 'language', 'speed'] );
        $selected = $voice ?: 'b76f1226-8170-4902-9482-36bb4fc98085'; // fallback: aura

        $request = $this->request( ['input_text' => $text] + $allowed + ['audio_format' => 'mp3'] );
        $response = $this->client()->post( "api/v1/voice/voices/{$selected}/generate", ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $jobId = $this->toData( $response, 'job_id' )['job_id'];
        $url = "api/v1/voice/tts-jobs/" . $jobId . "/status";
        return FileResponse::fromAsync( $this->download( $url, 'output_url' ), 3 );
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

        /** @var string */
        $jobId = $this->toData( $response, 'job_id' )['job_id'];
        return TextResponse::fromAsync( $this->transcription( $jobId ), 3 );
    }


    protected function download( string $url, string $key ) : \Closure
    {
        return function( FileResponse $fr ) use ( $url, $key ) : bool {

            $data = $this->toData( $this->getResponse( $url ) );

            if( @$data['status'] !== 'COMPLETED' ) {
                return false;
            }

            if( !@$data[$key] ) {
                throw new PrismaException( sprintf( 'Required key "%1$s" missing: %2$s', $key, print_r( $data, true ) ) );
            }

            foreach( (array) $data[$key] as $name => $url ) {
                /** @var string $url */
                $fr->add( Audio::fromUrl( $url ), $name );
            }

            return true;
        };
    }


    protected function getResponse( string $url ) : ResponseInterface
    {
        $response = $this->client()->get( $url );

        if( $response->getStatusCode() !== 200 ) {
            throw new PrismaException( $response->getReasonPhrase() );
        }

        return $response;
    }


    protected function revoiced( string $url ) : \Closure
    {
        return function( FileResponse $fr ) use ( $url ) : bool {

            $data = $this->toData( $this->getResponse( $url ) );

            if( @$data['status'] !== 'COMPLETED' ) {
                return false;
            }

            if( !@$data['output_path'] ) {
                throw new PrismaException( sprintf( 'Required key "%1$s" missing: %2$s', 'output_path', print_r( $data, true ) ) );
            }

            /** @var string */
            $outputPath = $data['output_path'];
            $fr->add( Audio::fromUrl( "https://media.audiopod.ai/" . $outputPath ) );

            return true;
        };
    }


    /**
     * Convert the response body to an associative array and validate the presence of a required key if specified.
     *
     * @param ResponseInterface $response The response to convert
     * @param string|null $key Optional key to validate in the response data
     * @return array<string|int, mixed> The response data as an associative array
     * @throws PrismaException If the response body is not valid JSON or if the required key is missing
     */
    protected function toData( ResponseInterface $response, ?string $key = null ) : array
    {
        $data = $this->fromJson( $response );

        if( $key && !isset( $data[$key] ) ) {
            throw new PrismaException( sprintf( 'Required key "%1$s" missing: %2$s', $key, print_r( $data, true ) ) );
        }

        return $data;
    }


    protected function transcription( string $id ) : \Closure
    {
        return function( TextResponse $tr ) use ( $id ) : bool {

            $data = $this->toData( $this->getResponse( "api/v1/transcription/jobs/{$id}" ) );

            if( @$data['status'] !== 'COMPLETED' ) {
                return false;
            }

            $data = $this->toData( $this->getResponse( "api/v1/transcription/transcript/{$id}" ) );

            /** @var array<int, array<string, mixed>> */
            $segments = $data['segments'] ?? [];

            $text = join( ' ', array_map( function( $segment ) {
                return $segment['text'];
            }, $segments ) );

            /** @var array<string, mixed> */
            $statistics = $data['statistics'] ?? [];

            $tr->add( $text )
                ->withStructured( $segments )
                ->withMeta( $statistics );

            return true;
        };
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $body = $response->getBody()->getContents();

        $this->throw( $response->getStatusCode(), $body ?: $response->getReasonPhrase() );
    }
}
