<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Gemini extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash' );

        $system = $this->systemPrompt() ? [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $this->systemPrompt()
                ]]
            ]] : [];

        $parts = array_map( fn( File $file ) => [
            'inlineData' => [
                'data' => $file->base64(),
                'mimeType' => $file->mimeType()
            ],
        ], $files );

        $parts[] = ['text' => $prompt];

        $request = $system + [
            'contents' => [[
                'parts' => $parts
            ]],
            'generationConfig' => [
                'responseModalities' => ['TEXT']
            ] + $this->allowed( $options, ['temperature', 'maxOutputTokens', 'topP', 'topK'] )
        ];

        $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $texts = [];

        foreach( $data['candidates'] ?? [] as $candidate )
        {
            foreach( $candidate['content']['parts'] ?? [] as $part )
            {
                if( $text = $part['text'] ?? null ) {
                    $texts[] = $text;
                }
            }
        }

        $first = current( $data['candidates'] ?? [] ) ?: [];

        return TextResponse::fromTexts( $texts )
            ->withMeta( $first['metadata'] ?? [] );
    }
}
