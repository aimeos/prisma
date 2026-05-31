<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Cohere as CohereBase;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Cohere extends CohereBase implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty'] );
        $options['response_format'] = [
            'type' => 'json_object',
            'json_schema' => $this->jsonSchema( $schema->toArray() ),
        ];

        $response = $this->generate(
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );

        $structured = json_decode( $response->text() ?? '', true ) ?: [];

        return $response->withStructured( $structured );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty'] );

        return $this->generate(
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }


    /**
     * Returns the JSON Schema adapted to Cohere's structured output requirements.
     *
     * Cohere closes objects and requires every object to declare at least one
     * required field, so objects without one fall back to requiring all properties.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> Adapted JSON Schema definition
     */
    protected function jsonSchema( array $schema ) : array
    {
        $type = $schema['type'] ?? null;

        if( $type === 'object' || ( is_array( $type ) && in_array( 'object', $type, true ) ) ) {
            $schema['additionalProperties'] = false;
        }

        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) )
        {
            if( empty( $schema['required'] ) ) {
                $schema['required'] = array_keys( $schema['properties'] );
            }

            $schema['properties'] = array_map( fn( array $prop ) => $this->jsonSchema( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->jsonSchema( $schema['items'] );
        }

        return $schema;
    }


    /**
     * Runs the tool loop for the Cohere Chat API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     */
    private function generate( array $messages, array $options ) : TextResponse
    {
        $allSteps = [];
        $rateLimit = null;
        $texts = [];
        $result = [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( 'command-a-plus-05-2026' ),
                'messages' => $messages,
            ] + $options
            + ( $this->maxTokens() ? ['max_tokens' => $this->maxTokens()] : [] );

            if( $tools = $this->toolsParam() ) {
                $params['tools'] = $tools;

                // Cohere only accepts "REQUIRED"/"NONE"; auto is the default and must be omitted.
                // The configured choice applies only on the first step so the model can
                // produce a final text answer after calling the tools.
                $choice = $step === 1 ? match( $this->toolChoice() ) {
                    self::REQ => 'REQUIRED',
                    self::NONE => 'NONE',
                    default => null,
                } : null;

                if( $choice ) {
                    $params['tool_choice'] = $choice;
                }
            }

            $response = $this->client()->post( 'v2/chat', ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $stepTexts = [];

            /** @var array<string, mixed> $message */
            $message = $result['message'] ?? [];
            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $message['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( $text = $block['text'] ?? null ) {
                    $stepTexts[] = $text;
                }
            }

            // Keep the last step that produced text so a tool-only final step (e.g.
            // when maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $stepTexts ?: $texts;

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = $message ?: ['role' => 'assistant', 'content' => null];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        return $this->result( $result, $allSteps, $texts, $rateLimit );
    }


    /**
     * Parses tool calls from Cohere API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function parseToolCalls( array $result ) : array
    {
        $toolCalls = [];

        /** @var array<string, mixed> $msg */
        $msg = $result['message'] ?? [];
        /** @var array<int, array<string, mixed>> $calls */
        $calls = $msg['tool_calls'] ?? [];

        foreach( $calls as $call )
        {
            /** @var string|null $id */
            $id = $call['id'] ?? null;
            /** @var array{name?: string, arguments?: string} $fn */
            $fn = $call['function'] ?? [];
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode( $fn['arguments'] ?? '{}', true ) ?: [];

            $toolCalls[] = [
                'id' => $id,
                'name' => $fn['name'] ?? '',
                'arguments' => $decoded,
            ];
        }

        return $toolCalls;
    }


    /**
     * Builds the TextResponse from a Cohere API result.
     *
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return TextResponse Text response
     */
    private function result( array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : TextResponse
    {
        $meta = $result;
        unset( $meta['message'], $meta['usage'] );

        /** @var array<string, mixed> $usageObj */
        $usageObj = $result['usage'] ?? [];
        /** @var array<string, mixed> $usage */
        $usage = $usageObj['tokens'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withReason( match( $result['finish_reason'] ?? null ) {
                'COMPLETE' => TextResponse::STOP,
                'MAX_TOKENS' => TextResponse::LENGTH,
                'ERROR', 'ERROR_TOXIC', 'ERROR_LIMIT' => TextResponse::ERROR,
                default => TextResponse::UNKNOWN,
            } )
            ->withUsage(
                ( isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (float) $usage['input_tokens'] : 0 )
                + ( isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (float) $usage['output_tokens'] : 0 ),
                $usage,
            )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
    }
}
