<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Mistral as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Values\RateLimit;


class Mistral extends Base implements Stream, Structure, Vectorize, Write
{
    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning_effort'] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        // Provider tools require the Mistral Agents API, which is not streamable. Run it
        // eagerly so HTTP/auth errors surface at the stream() call like every other provider,
        // then replay the whole answer as a single chunk so stream() consumers still get output.
        if( $this->providerTools() ) {
            $result = $this->agentResult( $messages, $options, $rateLimit );
            return TextResponse::fromStream( fn( TextResponse $res ) => $this->emitAgent( $res, $result ) )->withRateLimit( $rateLimit );
        }

        return $this->streamCompletions( 'v1/chat/completions', 'mistral-large-latest', $messages, $options );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning_effort'] );

        // Mistral rejects "response_format" together with "tools" in a single request. With
        // custom tools registered, embed the schema in the prompt and run the normal tool
        // loop, then parse the JSON from the final text instead of native structured output.
        if( $this->tools() )
        {
            $response = $this->completions(
                'v1/chat/completions', 'mistral-large-latest',
                $this->messages( $this->content( $schema->toPrompt( $prompt ), $files ) ),
                $options
            );

            return $response->withStructured( $this->parseJson( $response->text() ) );
        }

        return $this->structuredCompletions(
            'v1/chat/completions', 'mistral-large-latest',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['output_dtype'] );

        return $this->embeddings( 'v1/embeddings', 'mistral-embed', $texts, $size, $options, 'output_dimension' );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'reasoning_effort'] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        if( $this->providerTools() ) {
            $result = $this->agentResult( $messages, $options, $rateLimit );
            return TextResponse::fromStream( fn( TextResponse $res ) => $this->emitAgent( $res, $result ) )->withRateLimit( $rateLimit )->resolve();
        }

        return $this->completions(
            'v1/chat/completions', 'mistral-large-latest', $messages, $options
        );
    }


    /**
     * Runs the Mistral Agents API (required for provider tools) and returns its result.
     *
     * The Agents API is not streamable, so the whole exchange (create agent, run conversation)
     * runs eagerly here and both responses are validated, letting HTTP/auth errors surface at
     * the stream()/write() call instead of later when the response is consumed.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Request options
     * @param RateLimit|null $rateLimit Set to the conversation response's rate limit, left unchanged when it carries none
     * @return array<string, mixed> Conversation result
     */
    private function agentResult( array $messages, array $options, ?RateLimit &$rateLimit = null ) : array
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

        $rateLimit = $this->getRateLimit( $response ) ?? $rateLimit;

        return $this->fromJson( $response );
    }


    /**
     * Replays an already-fetched Agents API result as a single streamed chunk.
     *
     * The Agents API is not streamable, so the whole answer (fetched eagerly by agentResult())
     * is yielded as one chunk and folded into the response; the same generator backs stream()
     * (iterated live) and write() (drained via resolve()).
     *
     * @param TextResponse $res Response to populate
     * @param array<string, mixed> $result Conversation result from agentResult()
     * @return \Generator<int, string> Assembled answer text as a single chunk
     */
    private function emitAgent( TextResponse $res, array $result ) : \Generator
    {
        $texts = [];

        /** @var array<int, array<string, mixed>> $choices */
        $choices = $result['choices'] ?? [];

        foreach( $choices as $data )
        {
            /** @var array<string, mixed> $msg */
            $msg = $data['message'] ?? [];

            // keep falsy-but-valid content like "0"; skip only null/empty (mirrors the SSE delta guard)
            if( is_string( $text = $msg['content'] ?? null ) && $text !== '' ) {
                $texts[] = $text;
            }
        }

        $meta = $result;
        unset( $meta['choices'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        foreach( $texts as $text ) {
            yield $text;
        }

        $res->addAll( $texts )
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
