<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Mistral as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Mistral extends Base implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p'] );

        return $this->structuredCompletions(
            'v1/chat/completions', 'mistral-large-latest',
            $this->messages( $this->content( $prompt, $files ) ),
            $schema, $options
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p'] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        if( $this->providerTools() ) {
            return $this->createAgent( $messages, $options );
        }

        return $this->completions(
            'v1/chat/completions', 'mistral-large-latest', $messages, $options
        );
    }


    /**
     * Generates a text response using the Mistral Agents API (required for provider tools).
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Request options
     */
    private function createAgent( array $messages, array $options ) : TextResponse
    {
        $agentParams = [
            'model' => $this->modelName( 'mistral-large-latest' ),
        ] + $options;

        if( $tools = $this->toolsParam() ) {
            $agentParams['tools'] = $tools;
        }

        if( $system = $this->systemPrompt() ) {
            $agentParams['instructions'] = $system;
            $messages = array_values( array_filter( $messages, fn( $m ) => ( $m['role'] ?? '' ) !== 'system' ) );
        }

        $agentResponse = $this->client()->post( 'v1/agents', ['json' => $agentParams] );
        $this->validate( $agentResponse );
        $agentId = $this->fromJson( $agentResponse )['id'];

        $convParams = [
            'agent_id' => $agentId,
            'inputs' => $messages,
        ] + ( $this->maxTokens() ? ['max_tokens' => $this->maxTokens()] : [] );

        $response = $this->client()->post( 'v1/agents/conversations', ['json' => $convParams] );

        $this->validate( $response );

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

        $meta = $result;
        unset( $meta['choices'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        /** @var array<int, string|null> $texts */
        return TextResponse::fromTexts( $texts )
            ->withSteps( [] )
            ->withReason( match( $choices[0]['finish_reason'] ?? null ) {
                'stop' => TextResponse::STOP,
                'tool_calls' => TextResponse::TOOL,
                'length' => TextResponse::LENGTH,
                'content_filter' => TextResponse::CONTENT,
                default => TextResponse::UNKNOWN,
            } )
            ->withUsage(
                isset( $usage['total_tokens'] ) && is_numeric( $usage['total_tokens'] ) ? (float) $usage['total_tokens'] : null,
                $usage,
            )
            ->withMeta( $meta );
    }
}
