<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base implements Describe, Imagine, Repaint
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-3-pro-image' );
        $request = [
            'contents' => [[
                'parts' => [[
                    'inlineData' => [
                        'data' => $image->base64(),
                        'mimeType' => $image->mimeType()
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

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        /** @var array<string|null> $texts */
        $texts = [];

        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = $data['candidates'] ?? [];

        foreach( $candidates as $candidate )
        {
            /** @var array<string, mixed> $content */
            $content = $candidate['content'] ?? [];
            /** @var array<int, array<string, mixed>> $parts */
            $parts = $content['parts'] ?? [];

            foreach( $parts as $part )
            {
                if( $text = $part['text'] ?? null ) {
                    /** @var string $text */
                    $texts[] = $text;
                }
            }
        }

        /** @var array<int, array<string, mixed>> $candidatesArr */
        $candidatesArr = $data['candidates'] ?? [];
        $first = current( $candidatesArr ) ?: [];

        /** @var array<string, mixed> $metadata */
        $metadata = $first['metadata'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withMeta( $metadata );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'gemini-3-pro-image' );
        $allowed = $this->allowed( $options, ['aspectRatio'] );

        $system = $this->systemPrompt() ? [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $this->systemPrompt()
                ]]
            ]] : [];

        $config = !empty( $allowed ) ? [
            'imageConfig' => $allowed
        ] : [];

        $request = $system + [
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
                ...$config
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

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $files = [];
        /** @var string|null $description */
        $description = null;

        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = $data['candidates'] ?? [];

        foreach( $candidates as $candidate )
        {
            /** @var array<string, mixed> $content */
            $content = $candidate['content'] ?? [];
            /** @var array<int, array<string, mixed>> $parts */
            $parts = $content['parts'] ?? [];

            foreach( $parts as $part )
            {
                /** @var array<string, mixed>|null $inlineData */
                $inlineData = $part['inlineData'] ?? null;

                if( $inlineData && isset( $inlineData['data'] ) ) {
                    /** @var string $b64data */
                    $b64data = $inlineData['data'];
                    /** @var string|null $mimeType */
                    $mimeType = $inlineData['mimeType'] ?? null;
                    $files[] = Image::fromBase64( $b64data, $mimeType );
                } elseif( isset( $part['text'] ) ) {
                    /** @var string $description */
                    $description = $part['text'];
                }
            }
        }

        if( empty( $files ) ) {
            throw new \Aimeos\Prisma\Exceptions\PrismaException( 'No image data found in response' );
        }

        /** @var array<int, array<string, mixed>> $candidatesArr */
        $candidatesArr = $data['candidates'] ?? [];
        $first = current( $candidatesArr ) ?: [];

        /** @var array<string, mixed> $metadata */
        $metadata = $first['metadata'] ?? [];

        return FileResponse::fromFiles( $files )
            ->withMeta( $metadata )
            ->withDescription( $description );
    }
}
