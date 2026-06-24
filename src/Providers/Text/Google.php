<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Translate;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Google extends Base implements Translate
{
    private string $apiKey;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->apiKey = $this->config( $config, 'api_key' );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $this->config( $config, 'url', 'https://translation.googleapis.com' ) );
    }


    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
    {
        $payload = [
            'q' => array_values( $texts ),
            'target' => $to,
            'format' => 'text',
        ] + $this->allowed( $options, [
            'model',
        ] );

        if( $from ) {
            $payload['source'] = $from;
        }

        $response = $this->client()->post( '/language/translate/v2?key=' . $this->apiKey, ['json' => $payload] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        /** @var array<string, mixed> $dataObj */
        $dataObj = $data['data'] ?? [];
        /** @var array<int, array<string, mixed>> $translations */
        $translations = $dataObj['translations'] ?? [];
        $translated = array_map( fn( $item ) => $item['translatedText'] ?? '', $translations );

        /** @var array<int, string|null> $translated */
        return TextResponse::fromTexts( $translated );
    }
}
