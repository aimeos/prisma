<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Gemini extends Base implements Describe
{
    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-3.5-flash' );
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

        /** @var array<string, mixed> */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> */
        $candidates = $data['candidates'] ?? [];

        /** @var array<string, mixed> */
        $candidate = current( $candidates ) ?: [];

        /** @var array<string, mixed> */
        $content = $candidate['content'] ?? [];

        /** @var array<int, array<string, mixed>> */
        $parts = $content['parts'] ?? [];

        /** @var array<string, mixed> */
        $part = current( $parts ) ?: [];

        $text = $part['text'] ?? null;

        /** @var array<string, mixed> */
        $metadata = $candidate['metadata'] ?? [];

        return TextResponse::fromText( is_string( $text ) ? $text : null )
            ->withMeta( $metadata );
    }
}
