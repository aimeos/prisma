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
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function completions( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $allSteps = [];
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

            $response = $this->client()->post( $endpoint, ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            // Keep the last step that produced text so a tool-only final step (e.g.
            // when maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $this->completionTexts( $result ) ?: $texts;
            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            /** @var array<int, array<string, mixed>> $choices */
            $choices = $result['choices'] ?? [];
            $toolResults = $this->execTools( $toolCalls );
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
     * Builds chat messages array with optional system prompt and user content.
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
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode( $fn['arguments'] ?? '{}', true ) ?: [];
                $toolCalls[] = [
                    'id' => $callId,
                    'name' => $fn['name'] ?? '',
                    'arguments' => $decoded,
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
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function responses( string $endpoint, string $defaultModel, array $messages, array $options ) : \Aimeos\Prisma\Responses\TextResponse
    {
        $allSteps = [];
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

            $response = $this->client()->post( $endpoint, ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );

            /** @var array<int, array<string, mixed>> $output */
            $output = $result['output'] ?? [];
            $parsed = $this->responseData( $output );
            // Keep the last step that produced text so a tool-only final step (e.g.
            // when maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $parsed['texts'] ?: $texts;

            if( !$parsed['toolCalls'] ) {
                break;
            }

            $toolResults = $this->execTools( $parsed['toolCalls'] );
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
     * Runs a structured output request using the chat completions API.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param \Aimeos\Prisma\Schema\Schema $schema Response schema
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function structuredCompletions( string $endpoint, string $defaultModel, array $messages, \Aimeos\Prisma\Schema\Schema $schema, array $options ) : \Aimeos\Prisma\Responses\TextResponse
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

        $response = $this->completions( $endpoint, $defaultModel, $messages, $options );
        $structured = json_decode( $response->text() ?? '', true ) ?: [];

        return $response->withStructured( $structured );
    }


    /**
     * Runs a structured output request using the Responses API.
     *
     * @param string $endpoint API endpoint path
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param \Aimeos\Prisma\Schema\Schema $schema Response schema
     * @param array<string, mixed> $options Pre-filtered request options
     * @return \Aimeos\Prisma\Responses\TextResponse Text response
     */
    protected function structuredResponses( string $endpoint, string $defaultModel, array $messages, \Aimeos\Prisma\Schema\Schema $schema, array $options ) : \Aimeos\Prisma\Responses\TextResponse
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

        $response = $this->responses( $endpoint, $defaultModel, $messages, $options );
        $structured = json_decode( $response->text() ?? '', true ) ?: [];

        return $response->withStructured( $structured );
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
        $type = $schema['type'] ?? null;

        if( $type === 'object' || ( is_array( $type ) && in_array( 'object', $type, true ) ) ) {
            $schema['additionalProperties'] = false;
        }

        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
            $schema['properties'] = array_map( fn( array $prop ) => $this->jsonSchema( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->jsonSchema( $schema['items'] );
        }

        return $schema;
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

        return $schema;
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
                    'parameters' => $tool->schema()->toArray(),
                ],
            ];
        }

        return $tools;
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
        $thinking = $lastMsg['reasoning_content'] ?? null;
        $meta = $result;
        unset( $meta['choices'], $meta['usage'] );

        if( $thinking ) {
            $meta['thinking'] = $thinking;
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
            if( $text = $msg['content'] ?? null ) {
                $texts[] = $text;
            }
        }

        return $texts;
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
                    'arguments' => json_decode( $data['arguments'] ?? '{}', true ) ?: [],
                ];
                continue;
            }

            foreach( $data['content'] ?? [] as $content )
            {
                if( $text = $content['text'] ?? null ) {
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
}
