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
        $model = $this->modelName( 'gemini-2.5-flash' );
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

        $data = $this->fromJson( $response );
        $data = current( $data['candidates'] ?? [] ) ?: [];
        $part = current( $data['content']['parts'] ?? [] ) ?: [];

        return TextResponse::fromText( $part['text'] ?? null )
            ->withMeta( $data['metadata'] ?? [] );
    }
}
