<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Values\Mode;


/**
 * OpenAI-compatible API methods for chat completions and responses.
 */
trait OpenaiApi
{
    /**
     * Runs the chat completions tool loop for OpenAI-compatible APIs.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function completions( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        return \Aimeos\Prisma\Responses\TextResponse::fromStream(
            fn( \Aimeos\Prisma\Responses\TextResponse $res ) => $this->runCompletions( $res, $endpoint, $defaultModel, $messages, $options )
        )->resolve();
    }


    /**
     * Streams the chat completions tool loop as a lazy TextResponse for OpenAI-compatible APIs.
     *
     * Lazy dual of completions(): iterate the returned response for live deltas and tool steps,
     * or call any accessor to drain and assemble the final response.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Lazy streaming text response
     */
    protected function streamCompletions( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $params = $this->completionParams( $defaultModel, $messages, $options, 1, true );

        return $this->streamResponse( $endpoint, $params, fn( $res, $body ) =>
            $this->runCompletions( $res, $endpoint, $defaultModel, $messages, $options, $body )
        );
    }


    /**
     * Builds the request payload for one chat completions turn.
     *
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Provider specific options
     * @param int $step Current step in the tool loop (1-based)
     * @param bool $stream Whether to enable SSE streaming
     * @return array<string, mixed> Request payload
     */
    protected function completionParams( string $defaultModel, array $messages, array $options, int $step, bool $stream ) : array
    {
        $params = [
            'model' => $this->modelName( $defaultModel ),
            'messages' => $messages,
        ] + ( $stream ? ['stream' => true, 'stream_options' => ['include_usage' => true]] : [] ) + $options;

        if( $this->maxTokens() ) {
            $params['max_tokens'] = $this->maxTokens();
        }

        if( $thinkingBudget = $this->thinkingBudget() ) {
            $params['reasoning_effort'] = match( true ) {
                $thinkingBudget <= 1024 => 'low',
                $thinkingBudget <= 8192 => 'medium',
                default => 'high',
            };
        }

        return $this->applyTools( $params, $step );
    }


    /**
     * Adds the tools and step-aware tool choice to a request payload.
     *
     * The configured tool choice is applied only on the first step so the model can produce a
     * final text answer after calling the tools.
     *
     * @param array<string, mixed> $params Request payload
     * @param int $step Current step in the tool loop (1-based)
     * @return array<string, mixed> Request payload with tools applied
     */
    private function applyTools( array $params, int $step ) : array
    {
        if( $tools = $this->toolsParam( $params ) )
        {
            $params['tools'] = $tools;
            $choice = $step === 1 ? $this->toolChoiceParam() : 'auto';

            if( $choice !== null ) {
                $params['tool_choice'] = $choice;
            }
        }

        return $params;
    }


    /**
     * Runs the chat completions tool loop, optionally streaming.
     *
     * Single loop shared by write() (drained via completions()) and stream() (iterated lazily).
     * A primed $firstBody selects the streaming transport; tool calls always run through
     * execStream() and the assembled result is folded into the response when the loop ends.
     *
     * @param \Aimeos\Prisma\Responses\TextResponse $res Response to populate when the loop ends
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Provider specific options
     * @param \Psr\Http\Message\StreamInterface|null $firstBody Eagerly opened body for the first streamed turn; its presence enables streaming
     * @return \Generator<int, mixed> Text deltas and tool steps (empty when not streaming)
     */
    protected function runCompletions( \Aimeos\Prisma\Responses\TextResponse $res, string $endpoint, string $defaultModel, array $messages, array $options, ?\Psr\Http\Message\StreamInterface $firstBody = null ) : \Generator
    {
        $stream = $firstBody !== null;
        $allSteps = [];
        $calls = [];
        $texts = [];
        /** @var array<string, mixed> $result */
        $result = [];
        $rateLimit = null;

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = $this->completionParams( $defaultModel, $messages, $options, $step, $stream );

            $turn = $this->completionTurn( $endpoint, $params, $stream, $firstBody, $rateLimit );
            yield from $turn;                       // answer text deltas
            $result = $turn->getReturn();
            $firstBody = null;                      // consumed by the first turn

            // keep the last step that produced text so a tool-only final step doesn't discard it
            $texts = $this->completionTexts( $result ) ?: $texts;
            $toolCalls = $this->toolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            /** @var array<int, array<string, mixed>> $choices */
            $choices = $result['choices'] ?? [];
            $exec = $this->execStream( $toolCalls, $calls );
            yield from $exec;                       // tool steps before and after execution
            $toolResults = $exec->getReturn();

            array_push( $allSteps, ...$toolResults );
            $messages[] = $choices[0]['message'] ?? ['role' => 'assistant', 'content' => null];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        $this->applyCompletion( $res, $result, $allSteps, $texts, $rateLimit );
    }


    /**
     * Runs one chat completions turn over HTTP, yielding answer deltas and returning the result.
     *
     * Streaming opens (or reuses the primed first) SSE body and yields each delta via
     * readCompletion(), non-streaming POSTs once. Both return a result shaped like a regular
     * chat completions response so the shared loop and result builder can reuse it.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload for this turn
     * @param bool $stream Whether to stream the turn over SSE
     * @param \Psr\Http\Message\StreamInterface|null $firstBody Eagerly opened body reused for the first streamed turn
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Updated with this turn's rate limit
     * @return \Generator<int, string, mixed, array<string, mixed>> Text deltas, returning the result envelope
     */
    private function completionTurn( string $endpoint, array $params, bool $stream, ?\Psr\Http\Message\StreamInterface $firstBody, ?\Aimeos\Prisma\Values\RateLimit &$rateLimit ) : \Generator
    {
        if( !$stream ) {
            return $this->post( $endpoint, $params, $rateLimit );
        }

        $body = $firstBody ?? $this->openStream( $endpoint, $params, $rateLimit );
        $turn = $this->readCompletion( $body );
        yield from $turn;

        return $turn->getReturn();
    }


    /**
     * Builds content blocks with image URLs and a text prompt.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Image files
     * @return array<int, array<string, mixed>> Content blocks
     */
    protected function content( string $prompt, array $files ) : array
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() )
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $content;
    }


    /**
     * Creates embedding vectors for the OpenAI-compatible embeddings endpoint.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default embedding model name
     * @param array<int, string> $texts Input texts to embed
     * @param int|null $size Requested vector size or null for the provider default
     * @param array<string, mixed> $options Pre-filtered request options
     * @param string $sizeParam Request field carrying the vector size (e.g. "dimensions")
     * @return \Aimeos\Prisma\Responses\VectorResponse Embedding vector response
     */
    protected function embeddings( string $endpoint, string $defaultModel, array $texts, ?int $size, array $options, string $sizeParam = 'dimensions' ) : \Aimeos\Prisma\Responses\VectorResponse
    {
        $request = [
            'model' => $this->modelName( $defaultModel ),
            'input' => array_values( $texts ),
        ] + ( $size ? [$sizeParam => $size] : [] ) + $options;

        $response = $this->client()->post( $endpoint, ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> $items */
        $items = $data['data'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = array_map( fn( $item ) => $item['embedding'] ?? null, $items );

        /** @var array<string, mixed> $usage */
        $usage = $data['usage'] ?? [];
        $used = $usage['total_tokens'] ?? 0;

        $meta = $data;
        unset( $meta['data'], $meta['usage'] );

        return \Aimeos\Prisma\Responses\VectorResponse::fromVectors( $vectors )
            ->withUsage( is_numeric( $used ) ? (float) $used : 0, $usage )
            ->withMeta( $meta );
    }


    /**
     * Returns the JSON Schema with "additionalProperties" disabled on every object.
     *
     * The OpenAI-compatible chat completions and responses endpoints require
     * "additionalProperties": false on each object for strict schema adherence.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> JSON Schema definition with closed objects
     */
    protected function jsonSchema( array $schema ) : array
    {
        return \Aimeos\Prisma\Schema\Schema::map( $schema, function( array $node ) {
            $type = $node['type'] ?? null;

            if( $type === 'object' || ( is_array( $type ) && in_array( 'object', $type, true ) ) ) {
                $node['additionalProperties'] = false;
            }

            return $node;
        } );
    }


    /**
     * Builds chat messages array with optional system prompt, history and user content.
     *
     * @param array<int, array<string, mixed>> $content User message content blocks
     * @return array<int, array<string, mixed>> Messages array
     */
    protected function messages( array $content ) : array
    {
        $messages = [];

        if( $system = $this->systemPrompt() ) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        foreach( $this->history() as $msg )
        {
            $messages[] = $msg['role'] === 'assistant'
                ? ['role' => 'assistant', 'content' => $msg['content']]
                : ['role' => 'user', 'content' => $this->content( $msg['content'], $msg['files'] )];
        }

        $messages[] = ['role' => 'user', 'content' => $content];

        return $messages;
    }


    /**
     * Parses tool calls from the API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function toolCalls( array $result ) : array
    {
        $toolCalls = [];

        /** @var array<int, array<string, mixed>> $choices */
        $choices = $result['choices'] ?? [];

        foreach( $choices as $choice )
        {
            /** @var array<int, array<string, mixed>> $calls */
            $calls = $choice['message']['tool_calls'] ?? [];

            foreach( $calls as $call )
            {
                /** @var array{name?: string, arguments?: string} $fn */
                $fn = $call['function'] ?? [];
                /** @var string|null $callId */
                $callId = $call['id'] ?? null;
                $toolCalls[] = [
                    'id' => $callId,
                    'name' => $fn['name'] ?? '',
                    'arguments' => $this->jsonArgs( $fn['arguments'] ?? '{}' ),
                ];
            }
        }

        return $toolCalls;
    }


    /**
     * Runs the Responses API tool loop, drained eagerly into a TextResponse (used by OpenAI and xAI).
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function responses( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        return \Aimeos\Prisma\Responses\TextResponse::fromStream(
            fn( \Aimeos\Prisma\Responses\TextResponse $res ) => $this->runResponses( $res, $endpoint, $defaultModel, $messages, $options )
        )->resolve();
    }


    /**
     * Streams the Responses API tool loop as a lazy TextResponse (used by OpenAI and xAI).
     *
     * Lazy dual of responses(): iterate the returned response for live deltas and tool steps,
     * or call any accessor to drain and assemble the final response.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Input items
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Lazy streaming text response
     */
    protected function streamResponses( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $params = $this->responseParams( $defaultModel, $messages, $options, 1, true );

        return $this->streamResponse( $endpoint, $params, fn( $res, $body ) =>
            $this->runResponses( $res, $endpoint, $defaultModel, $messages, $options, $body )
        );
    }


    /**
     * Builds the request payload for one Responses API turn.
     *
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Input items
     * @param array<string, mixed> $options Provider specific options
     * @param int $step Current step in the tool loop (1-based)
     * @param bool $stream Whether to enable SSE streaming
     * @return array<string, mixed> Request payload
     */
    protected function responseParams( string $defaultModel, array $messages, array $options, int $step, bool $stream ) : array
    {
        $params = [
            'model' => $this->modelName( $defaultModel ),
            'input' => $messages,
        ] + ( $stream ? ['stream' => true] : [] ) + $options;

        if( $this->maxTokens() ) {
            $params['max_output_tokens'] = $this->maxTokens();
        }

        if( $prompt = $this->systemPrompt() ) {
            $params['instructions'] = $prompt;
        }

        return $this->applyTools( $params, $step );
    }


    /**
     * Runs the Responses API tool loop, optionally streaming.
     *
     * Single loop shared by write() (drained via responses()) and stream() (iterated lazily).
     * A primed $firstBody selects the streaming transport; tool calls always run through
     * execStream() and the assembled result is folded into the response when the loop ends.
     *
     * @param \Aimeos\Prisma\Responses\TextResponse $res Response to populate when the loop ends
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Input items
     * @param array<string, mixed> $options Provider specific options
     * @param \Psr\Http\Message\StreamInterface|null $firstBody Eagerly opened body for the first streamed turn; its presence enables streaming
     * @return \Generator<int, mixed> Text deltas and tool steps (empty when not streaming)
     */
    protected function runResponses( \Aimeos\Prisma\Responses\TextResponse $res, string $endpoint, string $defaultModel, array $messages, array $options, ?\Psr\Http\Message\StreamInterface $firstBody = null ) : \Generator
    {
        $stream = $firstBody !== null;
        $allSteps = [];
        $calls = [];
        $texts = [];
        /** @var array<string, mixed> $result */
        $result = [];
        $rateLimit = null;

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = $this->responseParams( $defaultModel, $messages, $options, $step, $stream );

            $turn = $this->responseTurn( $endpoint, $params, $stream, $firstBody, $rateLimit );
            yield from $turn;                       // answer text deltas
            $result = $turn->getReturn();
            $firstBody = null;                      // consumed by the first turn

            /** @var array<int, array<string, mixed>> $output */
            $output = $result['output'] ?? [];
            $parsed = $this->responseData( $output );
            // keep the last step that produced text so a tool-only final step doesn't discard it
            $texts = $parsed['texts'] ?: $texts;

            if( !$parsed['toolCalls'] ) {
                break;
            }

            $exec = $this->execStream( $parsed['toolCalls'], $calls );
            yield from $exec;                       // tool steps before and after execution
            $toolResults = $exec->getReturn();

            array_push( $allSteps, ...$toolResults );
            // Responses API expects the output items appended verbatim as top-level input items
            $messages = array_merge( $messages, $output, $this->responseSteps( $toolResults ) );
        }

        $this->applyResponse( $res, $result, $allSteps, $texts, $rateLimit );
    }


    /**
     * Runs one Responses API turn over HTTP, yielding answer deltas and returning the result.
     *
     * Streaming opens (or reuses the primed first) SSE body and yields each delta via
     * readResponse(), non-streaming POSTs once. Both return the final response envelope so the
     * shared loop and result builder can reuse it.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload for this turn
     * @param bool $stream Whether to stream the turn over SSE
     * @param \Psr\Http\Message\StreamInterface|null $firstBody Eagerly opened body reused for the first streamed turn
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Updated with this turn's rate limit
     * @return \Generator<int, string, mixed, array<string, mixed>> Text deltas, returning the result envelope
     */
    private function responseTurn( string $endpoint, array $params, bool $stream, ?\Psr\Http\Message\StreamInterface $firstBody, ?\Aimeos\Prisma\Values\RateLimit &$rateLimit ) : \Generator
    {
        if( !$stream ) {
            return $this->post( $endpoint, $params, $rateLimit );
        }

        $body = $firstBody ?? $this->openStream( $endpoint, $params, $rateLimit );
        $turn = $this->readResponse( $body );
        yield from $turn;

        return $turn->getReturn();
    }


    /**
     * Builds content blocks for the Responses API with input images and text.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Image files
     * @return array<int, array<string, mixed>> Content blocks
     */
    protected function responsesContent( string $prompt, array $files ) : array
    {
        $content = [['type' => 'input_text', 'text' => $prompt]];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'input_image',
                'image_url' => $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() )
            ];
        }

        return $content;
    }


    /**
     * Builds the Responses API input array from the history and current prompt.
     *
     * @param string $prompt Text prompt for the current turn
     * @param array<int, \Aimeos\Prisma\Files\File> $files Image files for the current turn
     * @return array<int, array<string, mixed>> Input items
     */
    protected function responsesInput( string $prompt, array $files ) : array
    {
        $input = [];

        foreach( $this->history() as $msg )
        {
            $input[] = $msg['role'] === 'assistant'
                ? ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $msg['content']]]]
                : ['role' => 'user', 'content' => $this->responsesContent( $msg['content'], $msg['files'] )];
        }

        $input[] = ['role' => 'user', 'content' => $this->responsesContent( $prompt, $files )];

        return $input;
    }


    /**
     * Runs a structured output request using the chat completions API.
     *
     * Native strict mode sends the schema as a json_schema response format; JSON mode embeds
     * the schema in the prompt and parses the JSON from the response text (default: native).
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param string $prompt User prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Input files
     * @param \Aimeos\Prisma\Schema\Schema $schema Response schema
     * @param array<string, mixed> $options Pre-filtered request options
     * @param string|null $mode Structured output mode ("json"/"structured") or null for automatic
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function structuredCompletions( string $endpoint, string $defaultModel, string $prompt, array $files, \Aimeos\Prisma\Schema\Schema $schema, array $options, ?string $mode = null ) : \Aimeos\Prisma\Responses\TextResponse
    {
        if( Mode::from( $mode )->isJson() ) {
            $options['response_format'] = ['type' => 'json_object'];
            $prompt = $schema->toPrompt( $prompt );
        } else {
            // Chat completions nest the schema under a "json_schema" key.
            $options['response_format'] = ['type' => 'json_schema', 'json_schema' => $this->structuredSchema( $schema )];
        }

        $response = $this->completions(
            $endpoint, $defaultModel,
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    /**
     * Runs a structured output request using the Responses API.
     *
     * Native strict mode sends the schema as a json_schema text format; JSON mode embeds the
     * schema in the prompt and parses the JSON from the response text (default: native).
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param string $prompt User prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Input files
     * @param \Aimeos\Prisma\Schema\Schema $schema Response schema
     * @param array<string, mixed> $options Pre-filtered request options
     * @param string|null $mode Structured output mode ("json"/"structured") or null for automatic
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function structuredResponses( string $endpoint, string $defaultModel, string $prompt, array $files, \Aimeos\Prisma\Schema\Schema $schema, array $options, ?string $mode = null ) : \Aimeos\Prisma\Responses\TextResponse
    {
        if( Mode::from( $mode )->isJson() ) {
            $options['text'] = ['format' => ['type' => 'json_object']];
            $prompt = $schema->toPrompt( $prompt );
        } else {
            // The Responses API carries the schema fields flat inside "format".
            $options['text'] = ['format' => ['type' => 'json_schema'] + $this->structuredSchema( $schema )];
        }

        $response = $this->responses(
            $endpoint, $defaultModel,
            $this->responsesInput( $prompt, $files ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    /**
     * Returns the provider-specific tool_choice value, or null to omit it.
     *
     * The default mapping matches the OpenAI API ("auto"/"required"/"none").
     * Providers supporting only a subset override this and return null for
     * unsupported choices so the field is omitted instead of causing an error.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return $this->toolChoice();
    }


    /**
     * Returns a tool's parameter schema, closed for strict mode when required.
     *
     * OpenAI strict function calling requires "additionalProperties": false on every
     * object and all properties listed as required, so strict tool schemas are run
     * through the same normalization as strict structured output.
     *
     * @param \Aimeos\Prisma\Schema\Schema $schema Tool parameter schema
     * @return array<string, mixed> Tool parameter definition
     */
    protected function toolParameters( \Aimeos\Prisma\Schema\Schema $schema ) : array
    {
        $params = $schema->toArray();

        if( $schema->isStrict() ) {
            $params = $this->requireAll( $this->jsonSchema( $params ) );
        }

        return $params;
    }


    /**
     * Builds tool result messages for the API request.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Step> $results Tool execution results
     * @return array<int, array<string, mixed>> Formatted tool result messages
     */
    protected function toolResults( array $results ) : array
    {
        $messages = [];

        foreach( $results as $step )
        {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $step->id(),
                'content' => $step->result(),
            ];
        }

        return $messages;
    }


    /**
     * Builds the tools parameter for the API request.
     *
     * @param array<string, mixed> $params Request payload
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam( array $params = [] ) : array
    {
        $tools = [];
        $op = isset( $params['response_format'] ) || isset( $params['text']['format'] ) ? self::STRUCT : self::GEN;
        $responses = isset( $params['input'] );

        foreach( $this->tools() as $tool )
        {
            $function = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $this->toolParameters( $tool->schema() ),
            ];

            $tools[] = $responses
                ? ['type' => 'function'] + $function + ['strict' => $tool->schema()->isStrict()]
                : ['type' => 'function', 'function' => $function];
        }

        return array_merge( $tools, $this->mapProviderTools( static::PROVIDER_TOOL_MAP, $op ) );
    }


    /**
     * Populates a TextResponse from a chat completions API result.
     *
     * Shared by the non-streaming and streaming paths so both assemble the same final response.
     *
     * @param \Aimeos\Prisma\Responses\TextResponse $res Response to populate
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     */
    private function applyCompletion( \Aimeos\Prisma\Responses\TextResponse $res, array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : void
    {
        /** @var array<int, array<string, mixed>> $choices */
        $choices = $result['choices'] ?? [];
        /** @var array<string, mixed> $lastMsg */
        $lastMsg = $choices[0]['message'] ?? [];
        // DeepSeek/Groq use "reasoning_content", OpenRouter uses "reasoning"
        $thinking = $lastMsg['reasoning_content'] ?? $lastMsg['reasoning'] ?? null;
        $meta = $result;
        unset( $meta['choices'], $meta['usage'] );

        if( $thinking ) {
            $meta['thinking'] = $thinking;
        }

        // OpenRouter returns encrypted reasoning blocks for multi-turn continuity
        if( isset( $lastMsg['reasoning_details'] ) ) {
            $meta['reasoning_details'] = $lastMsg['reasoning_details'];
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        $res->addAll( $texts );

        $res->withSteps( $allSteps )
            ->withCitations( $this->completionCitations( $result ) )
            ->withReason( $this->completionReason( $choices[0]['finish_reason'] ?? null ) )
            ->withUsage( $this->usageTokens( $usage ), $usage )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
    }


    /**
     * Builds citation values from the flat URL list some chat completions gateways return.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, \Aimeos\Prisma\Values\Citation> Citations
     */
    private function completionCitations( array $result ) : array
    {
        $citations = [];

        if( is_array( $result['citations'] ?? null ) ) {
            foreach( $result['citations'] as $url ) {
                $citations[] = new \Aimeos\Prisma\Values\Citation( url: is_string( $url ) ? $url : null );
            }
        }

        return $citations;
    }


    /**
     * Maps a chat completions finish_reason to a response reason constant.
     *
     * @param mixed $finish API finish_reason value
     * @return string Response reason constant
     */
    private function completionReason( mixed $finish ) : string
    {
        return match( $finish ) {
            'stop' => \Aimeos\Prisma\Responses\TextResponse::STOP,
            'tool_calls' => \Aimeos\Prisma\Responses\TextResponse::TOOL,
            'length' => \Aimeos\Prisma\Responses\TextResponse::LENGTH,
            'content_filter' => \Aimeos\Prisma\Responses\TextResponse::CONTENT,
            default => \Aimeos\Prisma\Responses\TextResponse::UNKNOWN,
        };
    }


    /**
     * Extracts the total token count from a usage block, or null when absent.
     *
     * @param array<string, mixed> $usage Usage block
     * @return float|null Total tokens used
     */
    private function usageTokens( array $usage ) : ?float
    {
        return isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (float) $usage['total_tokens'] : null;
    }


    /**
     * Extracts text content from chat completions choices.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, string> Extracted texts
     */
    private function completionTexts( array $result ) : array
    {
        $texts = [];

        /** @var array<int, array<string, mixed>> $choices */
        $choices = $result['choices'] ?? [];

        foreach( $choices as $data )
        {
            /** @var array<string, mixed> $msg */
            $msg = $data['message'] ?? [];
            $text = $msg['content'] ?? null;

            // Some OpenAI-compatible gateways (e.g. Cloudflare Workers AI) return the
            // content as an object/array instead of a string; serialize it so text() and
            // structured output (json_decode of the text) keep working.
            if( is_array( $text ) ) {
                $text = json_encode( $text );
            }

            if( $text !== null && $text !== '' ) {
                $texts[] = $text;
            }
        }

        return $texts;
    }


    /**
     * Recursively lists every property of an object in its "required" array.
     *
     * OpenAI strict mode requires all properties to be listed as required;
     * optional fields are expressed as nullable types instead.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> Schema with all properties required
     */
    private function requireAll( array $schema ) : array
    {
        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) )
        {
            $schema['required'] = array_keys( $schema['properties'] );
            $schema['properties'] = array_map( fn( array $prop ) => $this->requireAll( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->requireAll( $schema['items'] );
        }

        if( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
            $schema['anyOf'] = array_map( fn( array $sub ) => $this->requireAll( $sub ), $schema['anyOf'] );
        }

        if( isset( $schema['$defs'] ) && is_array( $schema['$defs'] ) ) {
            $schema['$defs'] = array_map( fn( array $sub ) => $this->requireAll( $sub ), $schema['$defs'] );
        }

        return $schema;
    }


    /**
     * Builds the strict-mode json_schema descriptor shared by both structured output APIs.
     *
     * Closes every object with the provider's jsonSchema() normalization and, in strict mode,
     * lists all properties as required, then returns the name/strict/schema triple that the chat
     * completions and Responses APIs each wrap in their own envelope.
     *
     * @param \Aimeos\Prisma\Schema\Schema $schema Response schema
     * @return array{name: string, strict: bool, schema: array<string, mixed>} Schema descriptor
     */
    private function structuredSchema( \Aimeos\Prisma\Schema\Schema $schema ) : array
    {
        $json = $this->jsonSchema( $schema->toArray() );

        return [
            'name' => $schema->name(),
            'strict' => $schema->isStrict(),
            'schema' => $schema->isStrict() ? $this->requireAll( $json ) : $json,
        ];
    }


    /**
     * Parses texts and tool calls from the Responses API output.
     *
     * @param array<int, array<string, mixed>> $output Response output blocks
     * @return array{texts: array<int, string>, toolCalls: array<int, array<string, mixed>>} Parsed data
     */
    private function responseData( array $output ) : array
    {
        $texts = [];
        $toolCalls = [];

        foreach( $output as $data )
        {
            if( ( $data['type'] ?? '' ) === 'function_call' ) {
                $toolCalls[] = [
                    'id' => $data['call_id'] ?? null,
                    'name' => $data['name'] ?? '',
                    'arguments' => $this->jsonArgs( $data['arguments'] ?? '{}' ),
                ];
                continue;
            }

            foreach( $data['content'] ?? [] as $content )
            {
                $text = $content['text'] ?? null;

                if( $text !== null && $text !== '' ) {
                    $texts[] = $text;
                }
            }
        }

        return ['texts' => $texts, 'toolCalls' => $toolCalls];
    }


    /**
     * Parses thinking and citations from the Responses API output.
     *
     * @param array<int, array<string, mixed>> $output Response output blocks
     * @param array<int, string|null> $texts Extracted text content
     * @return array{thinking: string|null, citations: array<int, \Aimeos\Prisma\Values\Citation>} Parsed output
     */
    private function responseOutput( array $output, array $texts ) : array
    {
        $thinking = null;

        /** @var array<int, \Aimeos\Prisma\Values\Citation> */
        $citations = [];
        $fullText = null;

        foreach( $output as $data )
        {
            if( ( $data['type'] ?? '' ) === 'reasoning' ) {
                /** @var array<int, array<string, mixed>> $summaries */
                $summaries = $data['summary'] ?? [];
                foreach( $summaries as $summary ) {
                    /** @var string $text */
                    $text = $summary['text'] ?? '';
                    $thinking .= $text;
                }
            }

            foreach( $data['content'] ?? [] as $content )
            {
                foreach( $content['annotations'] ?? [] as $ann )
                {
                    if( ( $ann['type'] ?? '' ) === 'url_citation' )
                    {
                        $fullText ??= implode( '', $texts );
                        $start = $ann['start_index'] ?? null;
                        $end = $ann['end_index'] ?? null;
                        $cited = is_int( $start ) && is_int( $end ) ? mb_substr( $fullText, $start, $end - $start ) : null;

                        $citations[] = new \Aimeos\Prisma\Values\Citation(
                            title: $ann['title'] ?? null,
                            url: $ann['url'] ?? null,
                            text: $cited ?: null,
                        );
                    }
                }
            }
        }

        return ['thinking' => $thinking, 'citations' => $citations];
    }


    /**
     * Populates a TextResponse from a Responses API result.
     *
     * Shared by the non-streaming and streaming paths so both assemble the same final response.
     *
     * @param \Aimeos\Prisma\Responses\TextResponse $res Response to populate
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     */
    private function applyResponse( \Aimeos\Prisma\Responses\TextResponse $res, array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : void
    {
        $parsed = $this->responseOutput( $result['output'] ?? [], $texts );

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        if( $parsed['thinking'] ) {
            $meta['thinking'] = $parsed['thinking'];
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        $res->addAll( $texts );

        $res->withSteps( $allSteps )
            ->withCitations( $parsed['citations'] )
            ->withReason( $this->responseReason( $result['status'] ?? null ) )
            ->withUsage( $this->usageTokens( $usage ), $usage )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
    }


    /**
     * Maps a Responses API status to a response reason constant.
     *
     * @param mixed $status API response status value
     * @return string Response reason constant
     */
    private function responseReason( mixed $status ) : string
    {
        return match( $status ) {
            'completed' => \Aimeos\Prisma\Responses\TextResponse::STOP,
            'incomplete' => \Aimeos\Prisma\Responses\TextResponse::LENGTH,
            'failed' => \Aimeos\Prisma\Responses\TextResponse::ERROR,
            default => \Aimeos\Prisma\Responses\TextResponse::UNKNOWN,
        };
    }


    /**
     * Builds tool result messages for the Responses API.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Step> $results Tool execution results
     * @return array<int, array<string, mixed>> Formatted tool result messages
     */
    private function responseSteps( array $results ) : array
    {
        $messages = [];

        foreach( $results as $step )
        {
            $messages[] = [
                'type' => 'function_call_output',
                'call_id' => $step->id(),
                'output' => $step->result(),
            ];
        }

        return $messages;
    }


    /**
     * Streams a chat completions request, yielding each text delta and returning the result.
     *
     * Accumulates the content, reasoning, citations and tool call argument fragments into a
     * result shaped like a regular chat completions response, returned via the generator value.
     *
     * @param \Psr\Http\Message\StreamInterface $body Open SSE body for this turn
     * @return \Generator<int, string, mixed, array<string, mixed>> Text deltas, returning the reassembled result
     */
    private function readCompletion( \Psr\Http\Message\StreamInterface $body ) : \Generator
    {
        $content = '';
        $reasoning = '';
        $finish = null;
        /** @var array<string, mixed> $meta */
        $meta = [];
        /** @var array<string, mixed> $usage */
        $usage = [];
        /** @var array<int, mixed> $citations */
        $citations = [];
        /** @var array<int, array<string, mixed>> $tools */
        $tools = [];
        $current = 0;

        foreach( $this->streamData( $body ) as $event )
        {
            // accumulate the envelope fields (id, model, ...) so meta() matches the non-streaming
            // response; the transient choices/usage/citations are re-added below instead
            $meta = array_diff_key( $event, ['choices' => true, 'usage' => true, 'citations' => true] ) + $meta;

            if( isset( $event['usage'] ) && is_array( $event['usage'] ) ) {
                $usage = $event['usage'];
            }

            if( isset( $event['citations'] ) && is_array( $event['citations'] ) ) {
                $citations = $event['citations'];
            }

            /** @var array<string, mixed> $choice */
            $choice = $event['choices'][0] ?? [];

            if( isset( $choice['finish_reason'] ) ) {
                $finish = $choice['finish_reason'];
            }

            /** @var array<string, mixed> $delta */
            $delta = $choice['delta'] ?? [];

            if( isset( $delta['content'] ) && is_string( $delta['content'] ) && $delta['content'] !== '' )
            {
                $content .= $delta['content'];
                yield $delta['content'];
            }

            // DeepSeek/Groq stream "reasoning_content", OpenRouter streams "reasoning"
            $reasoningDelta = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;

            if( is_string( $reasoningDelta ) ) {
                $reasoning .= $reasoningDelta;
            }

            /** @var array<int, array<string, mixed>> $calls */
            $calls = $delta['tool_calls'] ?? [];

            foreach( $calls as $call )
            {
                // correlate each fragment to its call by the provider-supplied index,
                // falling back to the current slot when it is omitted or invalid
                $i = $this->streamSlot( $call['index'] ?? $current, count( $tools ), $current );
                $current = $i;
                $tools[$i] ??= ['id' => null, 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];

                if( isset( $call['id'] ) ) {
                    $tools[$i]['id'] = $call['id'];
                }

                if( isset( $call['function']['name'] ) ) {
                    $tools[$i]['function']['name'] = $call['function']['name'];
                }

                if( isset( $call['function']['arguments'] ) ) {
                    $tools[$i]['function']['arguments'] .= $call['function']['arguments'];
                }
            }
        }

        $message = ['role' => 'assistant', 'content' => $content !== '' ? $content : null];

        if( $reasoning !== '' ) {
            $message['reasoning_content'] = $reasoning;
        }

        if( $tools )
        {
            ksort( $tools );
            $message['tool_calls'] = array_values( $tools );
        }

        // Report the same "object" the non-streamed response does, not the per-chunk variant.
        if( ( $meta['object'] ?? null ) === 'chat.completion.chunk' ) {
            $meta['object'] = 'chat.completion';
        }

        /** @var array<string, mixed> $result */
        $result = $meta + [
            'choices' => [['message' => $message, 'finish_reason' => $finish]],
            'usage' => $usage,
        ];

        if( $citations ) {
            $result['citations'] = $citations;
        }

        return $result;
    }


    /**
     * Streams a Responses API request, yielding each text delta and returning the result.
     *
     * Captures the final response envelope (output, usage, status, ...), returned via the
     * generator value so the shared result builder can reuse it.
     *
     * @param \Psr\Http\Message\StreamInterface $body Open SSE body for this turn
     * @return \Generator<int, string, mixed, array<string, mixed>> Text deltas, returning the reassembled result
     */
    private function readResponse( \Psr\Http\Message\StreamInterface $body ) : \Generator
    {
        /** @var array<string, mixed> $result */
        $result = [];

        foreach( $this->streamData( $body ) as $event )
        {
            if( ( $event['type'] ?? '' ) === 'response.output_text.delta' )
            {
                $delta = $event['delta'] ?? '';

                if( is_string( $delta ) && $delta !== '' ) {
                    yield $delta;
                }
            }
            elseif( isset( $event['response'] ) && is_array( $event['response'] ) )
            {
                /** @var array<string, mixed> $response */
                $response = $event['response'];
                $result = $response;
            }
        }

        return $result;
    }
}
