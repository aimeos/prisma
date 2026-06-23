<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Gemini extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK', 'serviceTier'] );

        return $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'parts' => $this->content( $prompt, $files )]] ),
            $options, $callback
        );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK', 'serviceTier'] );
        $options['responseMimeType'] = 'application/json';

        if( $this->isJsonMode( $mode ) ) {
            // JSON mode: keep responseMimeType but embed the schema in the prompt and
            // parse it from the response text instead of a native responseSchema.
            $prompt = $this->schemaPrompt( $prompt, $schema );
        } else {
            $options['responseSchema'] = $this->jsonSchema( $schema->toArray() );
        }

        $response = $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'parts' => $this->content( $prompt, $files )]] ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK', 'serviceTier'] );

        return $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'parts' => $this->content( $prompt, $files )]] ),
            $options
        );
    }


    /**
     * Returns the endpoint for a non-streaming generateContent request.
     *
     * @param string|null $model Model name
     * @return string Endpoint path
     */
    protected function generateEndpoint( ?string $model ) : string
    {
        return 'v1beta/models/' . $model . ':generateContent';
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
        $flip = array_flip( ['type', 'description', 'enum', 'properties', 'required', 'items', 'nullable', 'anyOf', '$ref', '$defs'] );

        return \Aimeos\Prisma\Schema\Schema::map( $schema, function( array $node ) use ( $flip ) {
            $node = array_intersect_key( $node, $flip );

            if( is_array( $node['type'] ?? null ) )
            {
                if( in_array( 'null', $node['type'], true ) ) {
                    $node['nullable'] = true;
                }

                $node['type'] = current( array_filter( $node['type'], fn( $type ) => $type !== 'null' ) ) ?: 'string';
            }

            // The OpenAPI subset has no null enum members and rejects empty-string members
            // ("enum[0]: cannot be empty"); nullability is carried by "nullable" instead and
            // an empty value can't be expressed as a literal, so drop both. If nothing
            // remains, drop the enum and leave a free-form value.
            if( isset( $node['enum'] ) && is_array( $node['enum'] ) )
            {
                $node['enum'] = array_values( array_filter( $node['enum'], fn( $v ) => $v !== null && $v !== '' ) );

                if( !$node['enum'] ) {
                    unset( $node['enum'] );
                }
            }

            return $node;
        } );
    }


    /**
     * Returns the endpoint for a streaming generateContent request.
     *
     * @param string|null $model Model name
     * @return string Endpoint path
     */
    protected function streamEndpoint( ?string $model ) : string
    {
        return 'v1beta/models/' . $model . ':streamGenerateContent?alt=sse';
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
     * @param callable|null $callback Stream consumer enabling SSE streaming when set
     */
    private function generate( array $contents, array $options, ?callable $callback = null ) : TextResponse
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

                if( $callback !== null )
                {
                    $data = $this->streamGenerate( $model, $request, $callback );
                    $rateLimit = $this->streamRateLimit;
                }
                else
                {
                    $response = $this->client()->post( $this->generateEndpoint( $model ), ['json' => $request] );

                    $this->validate( $response );

                    $rateLimit = $this->getRateLimit( $response );
                    $data = $this->fromJson( $response );
                }

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

            $toolResults = $this->execTools( $toolCalls, $calls, $callback );
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
        // service_tier (Flex Inference) is a top-level request field, not a generationConfig
        // entry, so it is pulled out before the remaining options are merged into the config.
        $serviceTier = $options['serviceTier'] ?? null;
        unset( $options['serviceTier'] );

        $genConfig = [
            'responseModalities' => ['TEXT']
        ] + $options;

        if( $this->maxTokens() ) {
            $genConfig['maxOutputTokens'] = $this->maxTokens();
        }

        // A positive budget caps thinking, withThinkingBudget(0) disables it explicitly
        // (Gemini turns thinking off for thinkingBudget=0), null leaves the model default.
        if( $this->thinkingBudget() !== null ) {
            $genConfig['thinkingConfig'] = ['thinkingBudget' => $this->thinkingBudget()];
        }

        $request = $system + [
            'contents' => $contents,
            'generationConfig' => $genConfig,
        ];

        if( $serviceTier !== null ) {
            $request['service_tier'] = $serviceTier;
        }

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

            $toolConfig = $mode ? ['functionCallingConfig' => ['mode' => $mode]] : [];

            // Gemini runs server-side provider tools (e.g. google_search) in the same turn
            // as custom function tools only when this flag is set.
            if( $this->tools() && $this->providerTools() ) {
                $toolConfig['includeServerSideToolInvocations'] = true;
            }

            if( $toolConfig ) {
                $request['toolConfig'] = $toolConfig;
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


    /**
     * Streams a Gemini generateContent request and rebuilds the non-streaming result.
     *
     * Forwards each answer-text delta to the callback while accumulating the streamed
     * parts (answer text, thinking and the complete functionCall parts), then returns a
     * result array shaped like a regular generateContent response so the shared tool
     * loop and result builder can reuse it.
     *
     * @param string|null $model Model name
     * @param array<string, mixed> $request Request payload
     * @param callable $callback Text delta consumer
     * @return array<string, mixed> Reassembled API result
     */
    private function streamGenerate( ?string $model, array $request, callable $callback ) : array
    {
        $text = '';
        $thinking = '';
        $finishReason = null;
        $signature = null;
        /** @var array<int, array<string, mixed>> $functionCalls */
        $functionCalls = [];
        /** @var array<string, mixed> $grounding */
        $grounding = [];
        /** @var array<string, mixed> $usage */
        $usage = [];

        // The "alt=sse" query switches the streaming endpoint to Server-Sent Events;
        // without it Gemini returns one large JSON array instead of one event per chunk.
        $endpoint = $this->streamEndpoint( $model );

        foreach( $this->streamData( $endpoint, $request ) as $event )
        {
            /** @var array<int, array<string, mixed>> $candidates */
            $candidates = $event['candidates'] ?? [];
            /** @var array<string, mixed> $candidate */
            $candidate = current( $candidates ) ?: [];

            /** @var array<int, array<string, mixed>> $parts */
            $parts = $candidate['content']['parts'] ?? [];

            foreach( $parts as $part )
            {
                if( isset( $part['functionCall'] ) ) {
                    $functionCalls[] = $part;
                } elseif( $part['thought'] ?? false ) {
                    $thinking .= $part['text'] ?? '';
                } elseif( ( $chunk = $part['text'] ?? '' ) !== '' ) {
                    $text .= $chunk;
                    $callback( $chunk );
                }

                // Gemini 3 thinking emits a thoughtSignature only once per turn; capture
                // the first one so it can be backfilled onto the other function calls.
                if( !empty( $part['thoughtSignature'] ) ) {
                    $signature ??= $part['thoughtSignature'];
                }
            }

            if( isset( $candidate['finishReason'] ) ) {
                $finishReason = $candidate['finishReason'];
            }

            // Grounding metadata and usage arrive complete in a later chunk, so the
            // latest non-empty value wins instead of being concatenated.
            if( !empty( $candidate['groundingMetadata'] ) && is_array( $candidate['groundingMetadata'] ) ) {
                $grounding = $candidate['groundingMetadata'];
            }

            if( !empty( $event['usageMetadata'] ) && is_array( $event['usageMetadata'] ) ) {
                $usage = $event['usageMetadata'];
            }
        }

        // Rebuild the candidate parts in the non-streaming order (thinking, answer, tool
        // calls) so candidateTexts(), parseToolCalls() and result() read the same shape.
        $parts = [];

        if( $thinking !== '' ) {
            $parts[] = ['text' => $thinking, 'thought' => true];
        }

        if( $text !== '' ) {
            $parts[] = ['text' => $text];
        }

        // Each replayed functionCall part must carry a thoughtSignature or Gemini 3 rejects
        // the follow-up turn; backfill the captured signature onto the calls missing it.
        if( $signature !== null )
        {
            foreach( $functionCalls as &$call )
            {
                if( empty( $call['thoughtSignature'] ) ) {
                    $call['thoughtSignature'] = $signature;
                }
            }

            unset( $call );
        }

        $parts = array_merge( $parts, $functionCalls );

        $candidate = ['content' => ['role' => 'model', 'parts' => $parts]];

        if( $finishReason !== null ) {
            $candidate['finishReason'] = $finishReason;
        }

        if( $grounding ) {
            $candidate['groundingMetadata'] = $grounding;
        }

        return [
            'candidates' => [$candidate],
            'usageMetadata' => $usage,
        ];
    }
}
