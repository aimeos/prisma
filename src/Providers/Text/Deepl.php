<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Translate;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Deepl extends Base implements Translate
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'DeepL-Auth-Key ' . $config['api_key'] );
        $this->baseUrl( $config['url'] ?? 'https://api-free.deepl.com' );
    }


    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
    {
        $payload = [
            'text' => array_values( $texts ), // reindex to ensure correct order
            'target_lang' => strtoupper( $to ),
        ] + $this->allowed( $options, [
            'formality', 'glossary_id', 'ignore_tags', 'model_type',
            'preserve_formatting', 'split_sentences', 'outline_detection',
            'non_splitting_tags', 'splitting_tags', 'tag_handling',
        ] );

        if( $from ) {
            $payload['source_lang'] = strtoupper( $from );
        }

        if( $context ) {
            $payload['context'] = $context;
        }

        $response = $this->client()->post( '/v2/translate', ['json' => $payload] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $translated = array_map( fn( $item ) => $item['text'] ?? '', $data['translations'] ?? [] );

        return TextResponse::fromTexts( $translated );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = @$this->fromJson( $response )['message'] ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
