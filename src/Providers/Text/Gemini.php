<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Gemini extends Base implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK'] );
        $options['responseMimeType'] = 'application/json';
        $options['responseSchema'] = $this->jsonSchema( $schema->toArray() );

        $response = $this->generate( [['parts' => $this->content( $prompt, $files )]], $options );
        $structured = json_decode( $response->text() ?? '', true ) ?: [];

        return $response->withStructured( $structured );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK'] );

        return $this->generate( [['parts' => $this->content( $prompt, $files )]], $options );
    }


    /**
     * Returns the JSON Schema reduced to the OpenAPI subset accepted by Gemini.
     *
     * Gemini's "responseSchema" is an OpenAPI 3.0 subset that has no
     * "additionalProperties" field and rejects unknown keys, so unsupported
     * keys are dropped recursively.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> JSON Schema definition limited to supported keys
     */
    protected function jsonSchema( array $schema ) : array
    {
        $keys = ['type', 'description', 'enum', 'properties', 'required', 'items', 'nullable', 'anyOf', '$ref', '$defs'];
        $schema = array_intersect_key( $schema, array_flip( $keys ) );

        if( is_array( $schema['type'] ?? null ) )
        {
            if( in_array( 'null', $schema['type'], true ) ) {
                $schema['nullable'] = true;
            }

            $schema['type'] = current( array_filter( $schema['type'], fn( $type ) => $type !== 'null' ) ) ?: 'string';
        }

        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
            $schema['properties'] = array_map( fn( array $prop ) => $this->jsonSchema( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->jsonSchema( $schema['items'] );
        }

        if( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
            $schema['anyOf'] = array_map( fn( array $sub ) => $this->jsonSchema( $sub ), $schema['anyOf'] );
        }

        if( isset( $schema['$defs'] ) && is_array( $schema['$defs'] ) ) {
            $schema['$defs'] = array_map( fn( array $sub ) => $this->jsonSchema( $sub ), $schema['$defs'] );
        }

        return $schema;
    }


    /**
     * Forces an empty functionCall "args" ([]) back to an object ({}) so the resent content is accepted.
     *
     * @param array<string, mixed> $content Model content with parts
     * @return array<string, mixed> Normalized content
     */
    private function assistantContent( array $content ) : array
    {
        if( !isset( $content['parts'] ) || !is_array( $content['parts'] ) ) {
            return $content;
        }

        foreach( $content['parts'] as &$part )
        {
            if( isset( $part['functionCall'] ) && empty( $part['functionCall']['args'] ) ) {
                $part['functionCall']['args'] = (object) [];
            }
        }

        return $content;
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
     * @param array<string, mixed> $options Pre-filtered request options
     */
    private function generate( array $contents, array $options ) : TextResponse
    {
        $model = $this->modelName( 'gemini-3.5-flash' );
        $allSteps = [];
        $calls = [];
        $rateLimit = null;
        $texts = [];
        $data = [];

        $system = ( $prompt = $this->systemPrompt() ) ? [
            'systemInstruction' => [
                'parts' => [['text' => $prompt]]
            ]] : [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $force = false;

            do
            {
                $request = $this->generateRequest( $system, $contents, $options, $step );

                // Gemini 2.5 can return MALFORMED_FUNCTION_CALL with empty parts when it tries
                // to emit a large or complex tool call in AUTO mode. Forcing a function call
                // (mode ANY) on a single retry makes it produce a well-formed call instead of
                // silently ending the tool loop with no result.
                if( $force ) {
                    $request['toolConfig'] = ['functionCallingConfig' => ['mode' => 'ANY']];
                }

                $response = $this->client()->post( 'v1beta/models/' . $model . ':generateContent', ['json' => $request] );

                $this->validate( $response );

                $rateLimit = $this->getRateLimit( $response );
                $data = $this->fromJson( $response );

                /** @var array<int, array<string, mixed>> $candidates */
                $candidates = $data['candidates'] ?? [];

                // Keep the last step that produced text so a tool-only final step (e.g.
                // when maxSteps is reached) doesn't discard the model's partial answer.
                $texts = $this->candidateTexts( $candidates ) ?: $texts;

                $toolCalls = $this->parseToolCalls( $data );

                $retry = !$force && !$toolCalls && $this->tools()
                    && ( $candidates[0]['finishReason'] ?? null ) === 'MALFORMED_FUNCTION_CALL';
                $force = true;
            }
            while( $retry );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls, $calls );
            array_push( $allSteps, ...$toolResults );

            $first = current( $candidates );
            if( $first ) {
                $contents[] = $this->assistantContent( $first['content'] ?? [] );
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
     * @param int $step Current step in the tool loop (1-based)
     * @return array<string, mixed> Request payload
     */
    private function generateRequest( array $system, array $contents, array $options, int $step ) : array
    {
        $genConfig = [
            'responseModalities' => ['TEXT']
        ] + $options;

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

            // Apply the configured tool choice only on the first step so the
            // model can produce a final text answer after calling the tools.
            $mode = $step === 1 ? match( $this->toolChoice() ) {
                self::AUTO => 'AUTO',
                self::REQ => 'ANY',
                self::NONE => 'NONE',
                default => null,
            } : 'AUTO';

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
     * @return array<int, \Aimeos\Prisma\Values\Citation> Parsed citations
     */
    private function parseCitations( array $candidate, array $texts ) : array
    {
        /** @var array<int, \Aimeos\Prisma\Values\Citation> */
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
