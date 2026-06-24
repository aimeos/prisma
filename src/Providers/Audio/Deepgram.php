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


class Deepgram extends Base implements Speak, Transcribe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Token ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.deepgram.com' ) );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $selected = $voice ?: $this->modelName( 'aura-2-thalia-en' );
        $params = ['model' => $selected] + $this->allowed( $options, [
            'callback', 'callback_method', 'mip_opt_out', 'tag', 'bit_rate', 'container', 'encoding', 'sample_rate'
        ] );

        $response = $this->client()->post( '/v1/speak?' . http_build_query( $params ), ['json' => ['text' => $text]] );

        $this->validate( $response );

        $mimetype = $response->getHeaderLine( 'Content-Type' ) ?: 'audio/mpeg';
        return FileResponse::fromBinary( $response->getBody()->getContents(), $mimetype );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $params = ['model' => $this->modelName( 'nova-3' )];

        if( $lang ) {
            $params['language'] = $lang;
        }

        $params += $this->allowed( $options, [
            'callback', 'callback_method', 'extra', 'sentiment', 'summarize', 'tag',
            'topics', 'custom_topic', 'custom_topic_mode', 'intents', 'custom_intent', 'custom_intent_mode',
            'detect_entities', 'detect_language', 'diarize', 'dictation', 'encoding', 'filler_words',
            'keyterm', 'keywords', 'measurements', 'multichannel', 'numerals', 'paragraphs',
            'profanity_filter', 'punctuate', 'redact', 'replace', 'search', 'smart_format',
            'utterances', 'utt_split', 'version', 'mip_opt_out'
        ] );

        $response = $this->client()->post( '/v1/listen?' . http_build_query( $params ), [
            'headers' => [
                'Content-Type' => $audio->mimetype() ?: 'audio/mpeg'
            ],
            'body' => $audio->binary()
        ] );

        $this->validate( $response );

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<string, mixed> */
        $results = $data['results'] ?? [];

        /** @var array<int, array<string, mixed>> */
        $channels = $results['channels'] ?? [];

        /** @var array<string, mixed> */
        $channel = $channels[0] ?? [];

        /** @var array<int, array<string, mixed>> */
        $alternatives = $channel['alternatives'] ?? [];

        /** @var array<string, mixed> */
        $alternative = $alternatives[0] ?? [];

        /** @var array<string, mixed> */
        $paragraphsData = $alternative['paragraphs'] ?? [];

        /** @var array<int, array<string, mixed>> */
        $lists = $paragraphsData['paragraphs'] ?? [];

        $sentences = array_merge( ...array_map( function( array $item ) : array {
            /** @var array<int, array<string, mixed>> */
            $s = $item['sentences'] ?? [];
            return $s;
        }, $lists ) );

        $transcript = $alternative['transcript'] ?? '';

        /** @var array<string, mixed> */
        $metadata = $data['metadata'] ?? [];

        return TextResponse::fromText( is_string( $transcript ) ? $transcript : '' )
            ->withStructured( $sentences )
            ->withMeta( $metadata );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = @$this->fromJson( $response )['err_msg'] ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), is_string( $error ) ? $error : '' );
        }
    }
}
