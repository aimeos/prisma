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
        $toolChoiceParam = $this->toolChoice();

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
                $params['tool_choice'] = $toolChoiceParam;
            }

            $response = $this->client()->post( $endpoint, ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
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

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = $choices[0]['message'] ?? ['role' => 'assistant', 'content' => null];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

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
                $citations[] = new \Aimeos\Prisma\Values\Citation( url: is_string( $url ) ? $url : null );
            }
        }

        /** @var array<int, string|null> $texts */
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
        $toolChoice = $this->toolChoice();

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
                $params['tool_choice'] = $toolChoice;
            }

            $response = $this->client()->post( $endpoint, ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );

            /** @var array<int, array<string, mixed>> $output */
            $output = $result['output'] ?? [];
            $parsed = $this->responseData( $output );
            $texts = $parsed['texts'];

            if( !$parsed['toolCalls'] ) {
                break;
            }

            $toolResults = $this->execTools( $parsed['toolCalls'] );
            array_push( $allSteps, ...$toolResults );
            $messages[] = ['role' => 'assistant', 'content' => $output];
            $messages = array_merge( $messages, $this->responseSteps( $toolResults ) );
        }

        return $this->responseResult( $result, $allSteps, $texts, $rateLimit );
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
}
