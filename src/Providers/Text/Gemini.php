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
        $parts = array_map( fn( File $file ) => [
            'inlineData' => [
                'data' => $file->base64(),
                'mimeType' => $file->mimeType()
            ],
        ], $files );

        $parts[] = ['text' => $prompt];

        $contents = [[
            'parts' => $parts
        ]];

        return $this->generate( $contents, $options );
    }


    /**
     * Generates a text response from the API.
     *
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Request options
     */
    private function generate( array $contents, array $options ) : TextResponse
    {
        $model = $this->modelName( 'gemini-3-flash-preview' );
        $allSteps = [];
        $rateLimit = [];
        $texts = [];
        $data = [];

        $system = ( $prompt = $this->systemPrompt() ) ? [
            'systemInstruction' => [
                'parts' => [[
                    'text' => $prompt
                ]]
            ]] : [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {

            $request = $system + [
                'contents' => $contents,
                'generationConfig' => [
                    'responseModalities' => ['TEXT']
                ] + $this->allowed( $options, ['temperature', 'maxOutputTokens', 'topP', 'topK'] )
            ];

            if( $tools = $this->toolsParam() ) {
                $request['tools'] = $tools;

                $mode = match( $this->toolChoice() ) {
                    'auto' => 'AUTO',
                    'required' => 'ANY',
                    'none' => 'NONE',
                    default => null,
                };

                if( $mode ) {
                    $request['toolConfig'] = ['functionCallingConfig' => ['mode' => $mode]];
                }
            }

            $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $data = $this->fromJson( $response );
            $texts = [];

            /** @var array<int, array<string, mixed>> $candidates */
            $candidates = $data['candidates'] ?? [];

            foreach( $candidates as $candidate )
            {
                /** @var array<string, mixed> $candidateContent */
                $candidateContent = $candidate['content'] ?? [];
                /** @var array<int, array<string, mixed>> $parts */
                $parts = $candidateContent['parts'] ?? [];

                foreach( $parts as $part )
                {
                    if( $text = $part['text'] ?? null ) {
                        $texts[] = $text;
                    }
                }
            }

            $toolCalls = $this->parseToolCalls( $data );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls );
            array_push( $allSteps, ...$toolResults );

            $first = current( $candidates );
            if( $first ) {
                $contents[] = $first['content'];
            }

            $resultMessages = $this->toolResults( $toolResults );
            $contents = array_merge( $contents, $resultMessages );
        }

        /** @var array<int, array<string, mixed>> $finalCandidates */
        $finalCandidates = $data['candidates'] ?? [];
        $first = current( $finalCandidates ) ?: [];

        /** @var array<string, mixed> $meta */
        $meta = is_array( $first['metadata'] ?? null ) ? $first['metadata'] : [];

        /** @var array<string, mixed> $usage */
        $usage = $data['usageMetadata'] ?? [];

        /** @var array<int, string|null> $texts */
        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withReason( match( $first['finishReason'] ?? null ) {
                'STOP' => TextResponse::STOP,
                'MAX_TOKENS' => TextResponse::LENGTH,
                'SAFETY', 'RECITATION' => TextResponse::CONTENT,
                default => TextResponse::UNKNOWN,
            } )
            ->withUsage(
                isset( $usage['totalTokenCount'] ) && is_numeric( $usage['totalTokenCount'] ) ? (float) $usage['totalTokenCount'] : null,
                $usage,
            )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
    }
}
