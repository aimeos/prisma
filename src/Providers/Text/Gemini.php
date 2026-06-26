<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Gemini as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Values\Mode;


class Gemini extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK', 'serviceTier'] );
        $contents = array_merge( $this->mapMessages(), [['role' => 'user', 'parts' => $this->content( $prompt, $files )]] );

        return $this->streamGenerate( $contents, $options );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'topP', 'topK', 'serviceTier'] );
        $options['responseMimeType'] = 'application/json';

        if( Mode::from( $mode )->isJson() ) {
            // JSON mode: keep responseMimeType but embed the schema in the prompt and
            // parse it from the response text instead of a native responseSchema.
            $prompt = $schema->toPrompt( $prompt );
        } else {
            $options['responseSchema'] = $this->jsonSchema( $schema->toArray() );
        }

        $response = $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'parts' => $this->content( $prompt, $files )]] ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $model = $this->modelName( 'gemini-embedding-001' );
        $allowed = $this->allowed( $options, ['taskType', 'title'] );

        $requests = array_map( fn( string $text ) => [
            'model' => 'models/' . $model,
            'content' => ['parts' => [['text' => $text]]],
        ] + ( $size ? ['outputDimensionality' => $size] : [] ) + $allowed, array_values( $texts ) );

        $response = $this->client()->post( 'v1beta/models/' . $model . ':batchEmbedContents', ['json' => ['requests' => $requests]] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> $embeddings */
        $embeddings = $data['embeddings'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = array_map( fn( $item ) => $item['values'] ?? null, $embeddings );

        return VectorResponse::fromVectors( $vectors );
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

            // the OpenAPI subset rejects null and empty-string enum members; drop them, and
            // drop a now-empty enum to leave a free-form value
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
     * Runs the Gemini tool loop, drained eagerly into a TextResponse.
     *
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Pre-filtered request options
     */
    private function generate( array $contents, array $options ) : TextResponse
    {
        $endpoint = $this->generateEndpoint( $this->modelName( 'gemini-3.5-flash' ) );

        return TextResponse::fromStream( fn( TextResponse $res ) => $this->runGenerate( $res, $endpoint, $contents, $options, false, $this->toolsParam() ) )->resolve();
    }


    /**
     * Streams the Gemini tool loop as a lazy TextResponse.
     *
     * Lazy dual of generate(): iterate the returned response for live deltas and tool steps,
     * or call any accessor to drain and assemble the final response.
     *
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Pre-filtered request options
     */
    private function streamGenerate( array $contents, array $options ) : TextResponse
    {
        $endpoint = $this->streamEndpoint( $this->modelName( 'gemini-3.5-flash' ) );
        $toolsParam = $this->toolsParam();
        $system = ( $prompt = $this->systemPrompt() ) ? [
            'systemInstruction' => ['parts' => [['text' => $prompt]]]
        ] : [];

        $params = $this->generateRequest( $system, $contents, $options, 1, $toolsParam );

        return $this->streamResponse( $endpoint, $params, fn( $res, $body ) =>
            $this->runGenerate( $res, $endpoint, $contents, $options, true, $toolsParam, $body )
        );
    }


    /**
     * Runs the Gemini tool loop, optionally streaming.
     *
     * Single loop shared by write() (drained via generate()) and stream() (iterated lazily).
     * The $stream flag selects the transport; tool calls always run through execStream(),
     * the MALFORMED_FUNCTION_CALL force-retry is applied and the assembled result is folded
     * into the response when the loop ends.
     *
     * @param TextResponse $res Response to populate when the loop ends
     * @param string $endpoint API endpoint path for this mode (built once by the caller)
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Pre-filtered request options
     * @param bool $stream Whether to stream the generation over SSE
     * @param array<int, array<string, mixed>> $toolsParam Pre-built tools payload, built once by the caller
     * @param \Psr\Http\Message\StreamInterface|null $firstBody Eagerly opened body for the first streamed turn
     * @return \Generator<int, mixed> Text deltas and tool steps (empty when not streaming)
     */
    private function runGenerate( TextResponse $res, string $endpoint, array $contents, array $options, bool $stream, array $toolsParam, ?\Psr\Http\Message\StreamInterface $firstBody = null ) : \Generator
    {
        $allSteps = [];
        $calls = [];
        $rateLimit = null;
        $texts = [];
        /** @var array<string, mixed> $data */
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
                if( $stream )
                {
                    if( $firstBody !== null ) {
                        $body = $firstBody;     // first turn reuses the eagerly opened body
                        $firstBody = null;
                    } else {
                        $body = $this->openStream( $endpoint, $this->generateRequest( $system, $contents, $options, $step, $toolsParam, $force ), $rateLimit );
                    }

                    $turn = $this->streamTurnGenerate( $body );
                    yield from $turn;                       // answer text deltas
                    $data = $turn->getReturn();
                }
                else
                {
                    $data = $this->post( $endpoint, $this->generateRequest( $system, $contents, $options, $step, $toolsParam, $force ), $rateLimit );
                }

                /** @var array<int, array<string, mixed>> $candidates */
                $candidates = $data['candidates'] ?? [];

                // keep the last step that produced text so a tool-only final step doesn't discard it
                $texts = $this->candidateTexts( $candidates ) ?: $texts;

                $toolCalls = $this->toolCalls( $data );

                // Gemini 2.5 can return MALFORMED_FUNCTION_CALL with empty parts; retry once with
                // $force so generateRequest() requests a function call (mode ANY) and gets one
                $retry = !$force && !$toolCalls && $this->tools()
                    && ( $candidates[0]['finishReason'] ?? null ) === 'MALFORMED_FUNCTION_CALL';
                $force = true;
            }
            while( $retry );

            if( !$toolCalls ) {
                break;
            }

            $exec = $this->execStream( $toolCalls, $calls );
            yield from $exec;                       // tool steps before and after execution
            $toolResults = $exec->getReturn();

            array_push( $allSteps, ...$toolResults );

            $first = current( $candidates );
            if( $first ) {
                $contents[] = $this->assistantContent( $first['content'] ?? [] );
            }

            $contents = array_merge( $contents, $this->toolResults( $toolResults ) );
        }

        $this->applyGemini( $res, $data, $allSteps, $texts, $rateLimit );
    }


    /**
     * Builds the request payload for the Gemini generateContent API.
     *
     * @param array<string, mixed> $system System instruction block
     * @param array<int, array<string, mixed>> $contents Chat contents
     * @param array<string, mixed> $options Request options
     * @param int $step Current step in the tool loop (1-based)
     * @param array<int, array<string, mixed>> $toolsParam Pre-built tools payload (hoisted out of the per-turn loop)
     * @param bool $force Force a function call (mode ANY) for the MALFORMED_FUNCTION_CALL retry
     * @return array<string, mixed> Request payload
     */
    private function generateRequest( array $system, array $contents, array $options, int $step, array $toolsParam, bool $force = false ) : array
    {
        // service_tier (Flex Inference) is a top-level request field, not a generationConfig entry
        $serviceTier = $options['serviceTier'] ?? null;
        unset( $options['serviceTier'] );

        $genConfig = [
            'responseModalities' => ['TEXT']
        ] + $options;

        if( $this->maxTokens() ) {
            $genConfig['maxOutputTokens'] = $this->maxTokens();
        }

        // a positive budget caps thinking, 0 disables it explicitly, null leaves the model default
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

        if( $toolsParam ) {
            $request['tools'] = $toolsParam;

            // apply the configured tool choice only on the first step, so the model can answer after
            $mode = $step === 1 ? match( $this->toolChoice() ) {
                self::AUTO => 'AUTO',
                self::REQUIRED => 'ANY',
                self::NONE => 'NONE',
                default => null,
            } : 'AUTO';

            $toolConfig = $mode ? ['functionCallingConfig' => ['mode' => $mode]] : [];

            // run server-side provider tools in the same turn as custom function tools
            if( $this->tools() && $this->providerTools() ) {
                $toolConfig['includeServerSideToolInvocations'] = true;
            }

            if( $toolConfig ) {
                $request['toolConfig'] = $toolConfig;
            }
        }

        // the MALFORMED_FUNCTION_CALL retry forces a function call (mode ANY)
        if( $force ) {
            $request['toolConfig'] = ['functionCallingConfig' => ['mode' => 'ANY']];
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
     * Populates a TextResponse from a Gemini API result.
     *
     * Shared by the non-streaming and streaming paths so both assemble the same final response.
     *
     * @param TextResponse $res Response to populate
     * @param array<string, mixed> $data API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     */
    private function applyGemini( TextResponse $res, array $data, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : void
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

        $res->addAll( $texts );

        $res->withSteps( $allSteps )
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
     * Streams a Gemini generateContent request, yielding deltas and returning the result.
     *
     * Accumulates the streamed parts (answer text, thinking and functionCall parts) into a
     * result shaped like a regular generateContent response, returned via the generator value.
     *
     * @param \Psr\Http\Message\StreamInterface $body Open SSE body for this turn
     * @return \Generator<int, string, mixed, array<string, mixed>> Text deltas, returning the reassembled result
     */
    private function streamTurnGenerate( \Psr\Http\Message\StreamInterface $body ) : \Generator
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

        foreach( $this->streamData( $body ) as $event )
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
                    yield $chunk;
                }

                // Gemini 3 emits a thoughtSignature once per turn; capture the first to backfill below
                if( !empty( $part['thoughtSignature'] ) ) {
                    $signature ??= $part['thoughtSignature'];
                }
            }

            if( isset( $candidate['finishReason'] ) ) {
                $finishReason = $candidate['finishReason'];
            }

            // grounding metadata and usage arrive complete in a later chunk, so the latest wins
            if( !empty( $candidate['groundingMetadata'] ) && is_array( $candidate['groundingMetadata'] ) ) {
                $grounding = $candidate['groundingMetadata'];
            }

            if( !empty( $event['usageMetadata'] ) && is_array( $event['usageMetadata'] ) ) {
                $usage = $event['usageMetadata'];
            }
        }

        // rebuild the parts in the non-streaming order (thinking, answer, tool calls)
        $parts = [];

        if( $thinking !== '' ) {
            $parts[] = ['text' => $thinking, 'thought' => true];
        }

        if( $text !== '' ) {
            $parts[] = ['text' => $text];
        }

        // Gemini 3 rejects the follow-up turn unless each replayed functionCall carries a
        // thoughtSignature; backfill the captured one onto the calls missing it
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
