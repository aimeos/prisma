<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base implements Describe, Imagine, Repaint
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-goog-api-key', (string) $config['api_key'] );
        $this->baseUrl( 'https://generativelanguage.googleapis.com' );
    }


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash-image' );
        $request = [
            'contents' => [[
                'parts' => [
                    'inlineData' => [
                        'data' => $image->base64(),
                        'mimeType' => $image->mimeType()
                    ],
                    ['text' => 'Describe the image in the language of ISO code "' . ( $lang ?? 'en' ) . '".']
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


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash-image' );
        $request = [
            'system_instruction' => [
                'parts' => [[
                    'text' => $this->systemPrompt()
                ]]
            ],
            'contents' => [[
                'parts' => [
                    ...array_map( fn( Image $img ) => [
                        'inlineData' => [
                            'data' => $img->base64(),
                            'mimeType' => $img->mimeType()
                        ],
                    ], $images ),
                    ['text' => $prompt]
                ]
            ]],
            'generationConfig' => [
                'responseModalities' => $options['responseModalities'] ?? ['TEXT', 'IMAGE'],
                'imageConfig' => $this->allowed( $options, ['aspectRatio'] ),
            ]
        ];
        $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->imagine( $prompt, [$image], $options );
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        $data = json_decode( $response->getBody()->getContents(), true ) ?? [];
        $data = current( $data['candidates'] ?? [] ) ?: null;
        $base64 = $mimeType = $description = null;

        foreach( $data['content']['parts'] ?? [] as $part )
        {
            if( isset( $part['inlineData']['data'] ) ) {
                $base64 = $part['inlineData']['data'];
                $mimeType = $part['inlineData']['mimeType'] ?? null;
            } elseif( isset( $part['text'] ) ) {
                $description = $part['text'];
            }
        }

        if( !$base64 ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        return FileResponse::fromBase64( $base64, $mimeType )
            ->withMeta( $data['metadata'] ?? [] )
            ->withDescription( $description );
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
