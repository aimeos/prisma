<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Image;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Files\Image as ImageFile;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base implements Image, Repaint
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new \InvalidArgumentException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-goog-api-key', $config['api_key'] );
        $this->baseUrl( 'https://generativelanguage.googleapis.com' );
    }


    public function image( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash-image' );
        $names = ['cachedContent', 'imageConfig', 'responseModalities', 'safetySettings', 'thinkingConfig'];
        $allowed = $this->allowed( $options, $names ) + ['responseModalities' => ['TEXT', 'IMAGE']];

        $request = [
            'contents' => [[
                'parts' => [[
                    ...array_map( fn( ImageFile $img ) => [
                        'inlineData' => [
                            'data' => $img->base64(),
                            'mimeType' => $img->mimeType()
                        ],
                    ], $images ),
                    ['text' => $prompt ]
                ]]
            ]],
            ...$allowed
        ];
        $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', $request );

        return $this->toFileResponse( $response );
    }


    public function repaint( ImageFile $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->image( $prompt, [$image], $options );
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
        if( $response->getStatusCode() !== 200 )
        {
            $error = json_decode( $response->getBody()->getContents() )?->error?->message ?? $response->getReasonPhrase();

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
}
