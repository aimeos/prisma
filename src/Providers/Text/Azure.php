<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Translate;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Azure as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


class Azure extends Base implements Stream, Structure, Translate, Vectorize, Write
{
    /** @var array<string, string> */
    private array $translatorHeaders;
    private string $translatorUrl;


    public function __construct( array $config )
    {
        parent::__construct( $config );

        $this->translatorUrl = rtrim( $this->config( $config, 'url', static::URL ), '/' );
        $this->translatorHeaders = array_filter( [
            'Ocp-Apim-Subscription-Key' => $this->config( $config, 'api_key' ),
            'Ocp-Apim-Subscription-Region' => $this->config( $config, 'region' ),
        ] );
    }


    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-5.6-sol' );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'chat/completions', $model, $messages, $options );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-5.6-sol' );

        return $this->structuredCompletions(
            'chat/completions', $model,
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
    {
        $query = ['api-version' => '3.0', 'to' => $to, 'from' => $from] + $this->allowed( $options, [
            'allowFallback', 'category', 'fromScript', 'profanityAction',
            'profanityMarker', 'suggestedFrom', 'textType', 'toScript',
        ] );

        $response = $this->client()->post( $this->translatorUrl . '/translate', [
            'json' => array_map( fn( $text ) => ['Text' => $text], array_values( $texts ) ),
            'query' => array_map( fn( $value ) => is_bool( $value ) ? var_export( $value, true ) : $value, $query ),
            'headers' => $this->translatorHeaders,
        ] );

        $this->validate( $response );

        /** @var array<int, array<string, mixed>> $data */
        $data = $this->fromJson( $response );
        /** @var array<int, string|null> $translated */
        $translated = array_map( fn( $item ) => $item['translations'][0]['text'] ?? '', $data );

        return TextResponse::fromTexts( $translated );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'user'] );
        $model = (string) $this->modelName( 'text-embedding-3-small' );

        return $this->embeddings( 'embeddings', $model, $texts, $size, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'] );
        $model = (string) $this->modelName( 'gpt-5.6-sol' );

        return $this->completions(
            'chat/completions', $model,
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
