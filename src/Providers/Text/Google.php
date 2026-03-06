<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Translate;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Google extends Base implements Translate
{
    private string $apiKey;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->apiKey = $config['api_key'];
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $config['url'] ?? 'https://translation.googleapis.com' );
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
        $translated = array_map( fn( $item ) => $item['translatedText'] ?? '', $data['data']['translations'] ?? [] );

        return TextResponse::fromTexts( $translated );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() !== 200 )
        {
            $error = @$this->fromJson( $response )['error']['message'] ?: $response->getReasonPhrase();
            $this->throw( $response->getStatusCode(), $error );
        }
    }
}
