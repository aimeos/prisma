<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base implements Describe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-goog-api-key', (string) $config['api_key'] );
        $this->baseUrl( 'https://generativelanguage.googleapis.com' );
    }


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash' );
        $request = [
            'contents' => [[
                'parts' => [[
                    'inlineData' => [
                        'data' => $audio->base64(),
                        'mimeType' => $audio->mimeType()
                    ]],
                    ['text' => 'Summarize the content of the file in a few words in plain text format in the language of ISO code "' . ( $lang ?? 'en' ) . '".']
                ]
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT']
            ]
        ];
        $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];
        $data = current( $data['candidates'] ?? [] ) ?: [];
        $part = current( $data['content']['parts'] ?? [] ) ?: [];

        return TextResponse::fromText( $part['text'] ?? null )
            ->withMeta( $data['metadata'] ?? [] );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = json_decode( $response->getBody()->getContents() )?->error?->message ?: $response->getReasonPhrase();

        switch( $response->getStatusCode() )
        {
            case 400:
            case 404: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
            case 401:
            case 403: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
            case 503: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
        }
    }
}
