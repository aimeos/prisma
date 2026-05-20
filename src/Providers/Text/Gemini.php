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
     * Extracts text content from Gemini candidate parts.
     *
     * @param array<int, array<string, mixed>> $candidates Candidate response blocks
     * @return array<int, string> Extracted texts
     */
    private function candidateTexts( array $candidates ) : array
    {
        $texts = [];

        foreach( $candidates as $candidate )
        {
            /** @var array<int, array<string, mixed>> $parts */
            $parts = $candidate['content']['parts'] ?? [];

            foreach( $parts as $part )
            {
                if( !( $part['thought'] ?? false ) && ( $text = $part['text'] ?? null ) ) {
                    $texts[] = $text;
                }
            }
        }

        return $texts;
    }


    /**
     * Runs the tool loop for the Gemini API.
     *
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Request options
     */
    private function generate( array $contents, array $options ) : TextResponse
    {
        $model = $this->modelName( 'gemini-2.5-flash' );
        $allSteps = [];
        $rateLimit = null;
        $texts = [];
        $data = [];

        $system = ( $prompt = $this->systemPrompt() ) ? [
            'systemInstruction' => [
                'parts' => [['text' => $prompt]]
            ]] : [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $request = $this->generateRequest( $system, $contents, $options );
            $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $data = $this->fromJson( $response );

            /** @var array<int, array<string, mixed>> $candidates */
            $candidates = $data['candidates'] ?? [];
            $texts = $this->candidateTexts( $candidates );

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

            $contents = array_merge( $contents, $this->toolResults( $toolResults ) );
        }

        return $this->result( $data, $allSteps, $texts, $rateLimit );
    }


    /**
     * Builds the request payload for the Gemini generateContent API.
     *
     * @param array<string, mixed> $system System instruction block
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Request options
     * @return array<string, mixed> Request payload
     */
    private function generateRequest( array $system, array $contents, array $options ) : array
    {
        $genConfig = [
            'responseModalities' => ['TEXT']
        ] + $this->allowed( $options, ['temperature', 'topP', 'topK'] );

        if( $this->maxTokens() ) {
            $genConfig['maxOutputTokens'] = $this->maxTokens();
        }

        if( $this->thinkingBudget() ) {
            $genConfig['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget()];
        }

        $request = $system + [
            'contents' => $contents,
            'generationConfig' => $genConfig,
        ];

        if( $tools = $this->toolsParam() ) {
            $request['tools'] = $tools;

            $mode = match( $this->toolChoice() ) {
                self::AUTO => 'AUTO',
                self::REQ => 'ANY',
                self::NONE => 'NONE',
                default => null,
            };

            if( $mode ) {
                $request['toolConfig'] = ['functionCallingConfig' => ['mode' => $mode]];
            }
        }

        return $request;
    }


    /**
     * Parses grounding citations from a Gemini candidate response.
     *
     * @param array<string, mixed> $candidate Candidate response data
     * @param array<int, string|null> $texts Extracted text content
     * @return array<int, array<string, mixed>> Parsed citations
     */
    private function parseCitations( array $candidate, array $texts ) : array
    {
        /** @var array<int, array<string, mixed>> */
        $citations = [];
        $grounding = $candidate['groundingMetadata'] ?? [];

        /** @var array<int, array<string, mixed>> $chunks */
        $chunks = $grounding['groundingChunks'] ?? [];

        /** @var array<int, array<string, mixed>> $supports */
        $supports = $grounding['groundingSupports'] ?? [];
        $fullText = null;

        foreach( $supports as $support )
        {
            $fullText ??= implode( '', $texts );

            /** @var array<string, mixed> $segment */
            $segment = $support['segment'] ?? [];
            $start = $segment['startIndex'] ?? null;
            $end = $segment['endIndex'] ?? null;
            $cited = is_int( $start ) && is_int( $end ) ? mb_substr( $fullText, $start, $end - $start ) : null;

            /** @var array<int, int> $indices */
            $indices = $support['groundingChunkIndices'] ?? [];

            foreach( $indices as $idx )
            {
                $web = $chunks[$idx]['web'] ?? [];
                $citations[] = new \Aimeos\Prisma\Values\Citation(
                    title: $web['title'] ?? null,
                    url: $web['uri'] ?? null,
                    text: $cited ?: null,
                );
            }
        }

        return $citations;
    }


    /**
     * Builds the TextResponse from a Gemini API result.
     *
     * @param array<string, mixed> $data API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return TextResponse Text response
     */
    private function result( array $data, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : TextResponse
    {
        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = $data['candidates'] ?? [];
        $first = current( $candidates ) ?: [];

        /** @var array<string, mixed> $meta */
        $meta = is_array( $first['metadata'] ?? null ) ? $first['metadata'] : [];

        $thinking = null;

        /** @var array<int, array<string, mixed>> $parts */
        $parts = $first['content']['parts'] ?? [];

        foreach( $parts as $part )
        {
            if( $part['thought'] ?? false ) {
                $thinking = $part['text'] ?? null;
            }
        }

        if( $thinking ) {
            $meta['thinking'] = $thinking;
        }

        $citations = $this->parseCitations( $first, $texts );

        /** @var array<string, mixed> $usage */
        $usage = $data['usageMetadata'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withCitations( $citations )
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
