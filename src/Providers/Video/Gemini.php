<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Gemini extends Base implements Describe
{
    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-3.5-flash' );
        $request = [
            'contents' => [[
                'parts' => [[
                    'inlineData' => [
                        'data' => $video->base64(),
                        'mimeType' => $video->mimeType()
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

        /** @var array<string, mixed> $candidate */
        $candidate = current( is_array( $data['candidates'] ?? null ) ? $data['candidates'] : [] ) ?: [];

        /** @var array<string, mixed> $content */
        $content = is_array( $candidate['content'] ?? null ) ? $candidate['content'] : [];

        /** @var array<string, mixed> $part */
        $part = current( is_array( $content['parts'] ?? null ) ? $content['parts'] : [] ) ?: [];

        /** @var array<string, mixed> $meta */
        $meta = is_array( $candidate['metadata'] ?? null ) ? $candidate['metadata'] : [];

        return TextResponse::fromText( is_string( $part['text'] ?? null ) ? $part['text'] : null )
            ->withMeta( $meta );
    }
}
