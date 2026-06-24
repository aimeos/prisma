<?php

namespace Aimeos\Prisma\Concerns;


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
     * @param callable|null $callback Stream consumer enabling SSE streaming when set
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function completions( string $endpoint, string $defaultModel, array $messages, array $options, ?callable $callback = null ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $allSteps = [];
        $calls = [];
        $texts = [];
        $result = [];
        $rateLimit = null;
        $toolsParam = $this->toolsParam();
        $toolChoiceParam = $this->toolChoiceParam();

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( $defaultModel ),
                'messages' => $messages,
            ] + $options;

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

            if( $toolsParam ) {
                $params['tools'] = $toolsParam;

                // Apply the configured tool choice only on the first step so the
                // model can produce a final text answer after calling the tools.
                $choice = $step === 1 ? $toolChoiceParam : 'auto';

                if( $choice !== null ) {
                    $params['tool_choice'] = $choice;
                }
            }

            if( $callback !== null )
            {
                $params['stream'] = true;
                $params['stream_options'] = ['include_usage' => true];

                $result = $this->streamCompletion( $endpoint, $params, $callback );
                $rateLimit = $this->streamRateLimit;
            }
            else
            {
                $response = $this->client()->post( $endpoint, ['json' => $params] );

                $this->validate( $response );

                $rateLimit = $this->getRateLimit( $response );
                $result = $this->fromJson( $response );
            }

            // Keep the last step that produced text so a tool-only final step (e.g.
            // when maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $this->completionTexts( $result ) ?: $texts;
            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            /** @var array<int, array<string, mixed>> $choices */
            $choices = $result['choices'] ?? [];
            $toolResults = $this->execTools( $toolCalls, $calls, $callback );
            array_push( $allSteps, ...$toolResults );
            $messages[] = $choices[0]['message'] ?? ['role' => 'assistant', 'content' => null];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        return $this->completionResult( $result, $allSteps, $texts, $rateLimit );
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
    protected function parseToolCalls( array $result ) : array
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
     * Runs the Responses API tool loop (used by OpenAI and xAI).
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     * @param callable|null $callback Stream consumer enabling SSE streaming when set
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function responses( string $endpoint, string $defaultModel, array $messages, array $options, ?callable $callback = null ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $allSteps = [];
        $calls = [];
        $texts = [];
        $result = [];
        $rateLimit = null;
        $tools = $this->toolsParam();
        $toolChoice = $this->toolChoiceParam();

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( $defaultModel ),
                'input' => $messages,
            ] + $options;

            if( $this->maxTokens() ) {
                $params['max_output_tokens'] = $this->maxTokens();
            }

            if( $prompt = $this->systemPrompt() ) {
                $params['instructions'] = $prompt;
            }

            if( $tools ) {
                $params['tools'] = $tools;

                // Apply the configured tool choice only on the first step so the
                // model can produce a final text answer after calling the tools.
                $choice = $step === 1 ? $toolChoice : 'auto';

                if( $choice !== null ) {
                    $params['tool_choice'] = $choice;
                }
            }

            if( $callback !== null )
            {
                $params['stream'] = true;
                $result = $this->streamResponse( $endpoint, $params, $callback );
                $rateLimit = $this->streamRateLimit;
            }
            else
            {
                $response = $this->client()->post( $endpoint, ['json' => $params] );

                $this->validate( $response );

                $rateLimit = $this->getRateLimit( $response );
                $result = $this->fromJson( $response );
            }

            /** @var array<int, array<string, mixed>> $output */
            $output = $result['output'] ?? [];
            $parsed = $this->responseData( $output );
            // Keep the last step that produced text so a tool-only final step (e.g.
            // when maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $parsed['texts'] ?: $texts;

            if( !$parsed['toolCalls'] ) {
                break;
            }

            $toolResults = $this->execTools( $parsed['toolCalls'], $calls, $callback );
            array_push( $allSteps, ...$toolResults );
            // Responses API expects the output items (function_call, message, reasoning)
            // appended verbatim as top-level input items, not wrapped in an assistant message.
            $messages = array_merge( $messages, $output, $this->responseSteps( $toolResults ) );
        }

        return $this->responseResult( $result, $allSteps, $texts, $rateLimit );
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
     * Native strict mode sends the schema as a json_schema response format; JSON mode
     * embeds the schema in the prompt and parses the JSON from the response text. The
     * "mode" option forces either path, otherwise it is chosen automatically.
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
        if( $this->isJsonMode( $mode ) )
        {
            $options['response_format'] = ['type' => 'json_object'];
            $prompt = $this->schemaPrompt( $prompt, $schema );
        }
        else
        {
            $json = $this->jsonSchema( $schema->toArray() );

            if( $schema->isStrict() ) {
                $json = $this->requireAll( $json );
            }

            $options['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schema->name(),
                    'strict' => $schema->isStrict(),
                    'schema' => $json,
                ],
            ];
        }

        $response = $this->completions( $endpoint, $defaultModel, $this->messages( $this->content( $prompt, $files ) ), $options );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    /**
     * Runs a structured output request using the Responses API.
     *
     * Native strict mode sends the schema as a json_schema text format; JSON mode embeds
     * the schema in the prompt and parses the JSON from the response text. The "mode"
     * option forces either path, otherwise it is chosen automatically.
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
        if( $this->isJsonMode( $mode ) )
        {
            $options['text'] = ['format' => ['type' => 'json_object']];
            $prompt = $this->schemaPrompt( $prompt, $schema );
        }
        else
        {
            $json = $this->jsonSchema( $schema->toArray() );

            if( $schema->isStrict() ) {
                $json = $this->requireAll( $json );
            }

            $options['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schema->name(),
                    'strict' => $schema->isStrict(),
                    'schema' => $json,
                ],
            ];
        }

        $response = $this->responses( $endpoint, $defaultModel, $this->responsesInput( $prompt, $files ), $options );

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
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $tools = [];

        foreach( $this->tools() as $tool )
        {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $this->toolParameters( $tool->schema() ),
                ],
            ];
        }

        return $tools;
    }


    /**
     * Builds the TextResponse from a chat completions API result.
     *
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    private function completionResult( array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : \Aimeos\Prisma\Responses\TextResponse
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

        /** @var array<int, \Aimeos\Prisma\Values\Citation> */
        $citations = [];

        if( is_array( $result['citations'] ?? null ) ) {
            foreach( $result['citations'] as $url ) {
                $citations[] = new \Aimeos\Prisma\Values\Citation(
                    url: is_string( $url ) ? $url : null,
                );
            }
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        return \Aimeos\Prisma\Responses\TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withCitations( $citations )
            ->withReason( match( $choices[0]['finish_reason'] ?? null ) {
                'stop' => \Aimeos\Prisma\Responses\TextResponse::STOP,
                'tool_calls' => \Aimeos\Prisma\Responses\TextResponse::TOOL,
                'length' => \Aimeos\Prisma\Responses\TextResponse::LENGTH,
                'content_filter' => \Aimeos\Prisma\Responses\TextResponse::CONTENT,
                default => \Aimeos\Prisma\Responses\TextResponse::UNKNOWN,
            } )
            ->withUsage(
                isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (float) $usage['total_tokens'] : null,
                $usage,
            )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
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
     * Builds the TextResponse from a Responses API result.
     *
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    private function responseResult( array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $parsed = $this->responseOutput( $result['output'] ?? [], $texts );

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        if( $parsed['thinking'] ) {
            $meta['thinking'] = $parsed['thinking'];
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        return \Aimeos\Prisma\Responses\TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withCitations( $parsed['citations'] )
            ->withReason( match( $result['status'] ?? null ) {
                'completed' => \Aimeos\Prisma\Responses\TextResponse::STOP,
                'incomplete' => \Aimeos\Prisma\Responses\TextResponse::LENGTH,
                'failed' => \Aimeos\Prisma\Responses\TextResponse::ERROR,
                default => \Aimeos\Prisma\Responses\TextResponse::UNKNOWN,
            } )
            ->withUsage(
                isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (float) $usage['total_tokens'] : null,
                $usage,
            )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
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
     * Streams a chat completions request and rebuilds the non-streaming result.
     *
     * Forwards each text delta to the callback while accumulating the assistant
     * content, reasoning, citations and tool call argument fragments, then returns a
     * result array shaped like a regular chat completions response so the shared tool
     * loop and result builder can reuse it.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload with streaming enabled
     * @param callable $callback Text delta consumer
     * @return array<string, mixed> Reassembled API result
     */
    private function streamCompletion( string $endpoint, array $params, callable $callback ) : array
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

        foreach( $this->streamData( $endpoint, $params ) as $event )
        {
            // Keep the envelope fields (id, model, created, ...) so meta() matches the non-streaming
            // response; later chunks win per key so the final resolved values are reported.
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
                $callback( $delta['content'] );
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
                // Prefer the provider index; fall back to a fresh slot for a new call
                // (it carries an id) or the current slot for an argument-only fragment.
                $i = $call['index'] ?? ( isset( $call['id'] ) ? count( $tools ) : $current );
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
     * Streams a Responses API request and returns the final response object.
     *
     * Forwards each output text delta to the callback. The terminal events
     * (response.completed/incomplete/failed) carry the full response object, which
     * is returned as-is so the shared Responses tool loop can reuse it.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $params Request payload with streaming enabled
     * @param callable $callback Text delta consumer
     * @return array<string, mixed> Final response object
     */
    private function streamResponse( string $endpoint, array $params, callable $callback ) : array
    {
        /** @var array<string, mixed> $result */
        $result = [];

        foreach( $this->streamData( $endpoint, $params ) as $event )
        {
            if( ( $event['type'] ?? '' ) === 'response.output_text.delta' )
            {
                $delta = $event['delta'] ?? '';

                if( is_string( $delta ) && $delta !== '' ) {
                    $callback( $delta );
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
