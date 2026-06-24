<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Elevenlabs extends Base implements Revoice, Speak, Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'xi-api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.elevenlabs.io' ) );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'eleven_english_sts_v2' );

        $allowed = $this->allowed( $options, [
            'voice_settings', 'seed', 'remove_background_noise', 'file_format'
        ] );

        $request = $this->payload( ['model_id' => $model] + $allowed, ['audio' => $audio] );
        $response = $this->client()->post( '/v1/speech-to-speech/' . $voice, ['multipart' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: 'JBFqnCBsd6RMkjVDRZzb';
        $model = $this->modelName( 'eleven_v3' );

        $allowed = $this->allowed( $options, [
            'language_code', 'voice_settings', 'pronunciation_dictionary_locators', 'seed',
            'previous_text', 'next_text', 'previous_request_ids', 'next_request_ids',
            'apply_text_normalization', 'apply_language_text_normalization'
        ] );

        $request = ['model_id' => $model, 'text' => $text] + $allowed;
        $response = $this->client()->post( '/v1/text-to-speech/' . $selected, ['json' => $request] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, [
            'tag_audio_events', 'num_speakers', 'diarize', 'diarization_threshold',
            'file_format', 'temperature', 'seed', 'use_multi_channel', 'timestamps_granularity'
        ] );

        $files = [];
        $request = [
            'model_id' => $this->modelName( 'scribe_v2' ),
        ] + $allowed;

        if( $lang ) {
            $request['language_code'] = $lang;
        }

        if( $audio->url() ) {
            $request['cloud_storage_url'] = $audio->url();
        } else {
            $files = ['file' => $audio];
        }

        $request = $this->payload( $request, $files );
        $response = $this->client()->post( 'v1/speech-to-text', ['multipart' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<string, mixed> */
        $usage = $data['usage'] ?? [];
        $totalTokens = $usage['total_tokens'] ?? 0;

        /** @var array<int, array<string, mixed>> */
        $words = $data['words'] ?? [];

        $text = $data['text'] ?? '';

        return TextResponse::fromText( is_string( $text ) ? $text : '' )
            ->withUsage( is_numeric( $totalTokens ) ? (float) $totalTokens : 0, $usage )
            ->withStructured( $words );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $detail = @$this->fromJson( $response )['detail'] ?? [];
            $error = is_array( $detail ) ? ( $detail['message'] ?? $response->getReasonPhrase() ) : $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), is_string( $error ) ? $error : '' );
        }
    }
}
