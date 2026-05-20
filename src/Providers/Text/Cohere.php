<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Cohere as CohereBase;
use Aimeos\Prisma\Responses\TextResponse;


class Cohere extends CohereBase implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        return $this->generate(
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }


    /**
     * Runs the tool loop for the Cohere Chat API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Request options
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
                'model' => $this->modelName( 'command-a-vision-07-2025' ),
                'messages' => $messages,
            ] + $this->allowed( $options, ['temperature', 'top_p', 'top_k', 'frequency_penalty', 'presence_penalty'] )
            + ( $this->maxTokens() ? ['max_tokens' => $this->maxTokens()] : [] );

            if( $tools = $this->toolsParam() ) {
                $params['tools'] = $tools;
                $params['tool_choice'] = $this->toolChoice();
            }

            $response = $this->client()->post( 'v2/chat', ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $texts = [];

            /** @var array<string, mixed> $message */
            $message = $result['message'] ?? [];
            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $message['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( $text = $block['text'] ?? null ) {
                    $texts[] = $text;
                }
            }

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
     * @param array<string, mixed> $rateLimit Rate limit information
     * @return TextResponse Text response
     */
    private function result( array $result, array $allSteps, array $texts, array $rateLimit ) : TextResponse
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
