<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Anthropic as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Anthropic extends Base implements Stream, Structure, Write
{
    public function stream( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse
    {
        $options = $this->allowed( $options, ['citations', 'temperature', 'top_p', 'top_k', 'thinking', 'effort'] );

        return $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $prompt, $files )]] ),
            $options, $callback
        );
    }



    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k', 'thinking', 'effort'] );

        if( $this->isJsonMode( $mode ) )
        {
            // JSON mode: embed the schema in the prompt and parse the JSON from the response text.
            $messages = array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $this->schemaPrompt( $prompt, $schema ), $files )]] );
        }
        else
        {
            $options['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $this->jsonSchema( $schema->toArray() )]];
            $messages = array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $prompt, $files )]] );
        }

        $response = $this->generate( $messages, $options );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['citations', 'temperature', 'top_p', 'top_k', 'thinking', 'effort'] );

        return $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $prompt, $files )]] ),
            $options
        );
    }


    /**
     * Returns the JSON Schema with "additionalProperties" disabled on every object.
     *
     * Anthropic's structured outputs require "additionalProperties": false on each
     * object and reject any other value.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> JSON Schema definition with closed objects
     */
    protected function jsonSchema( array $schema ) : array
    {
        return \Aimeos\Prisma\Schema\Schema::map( $schema, function( array $node ) {
            $type = $node['type'] ?? null;

            // Anthropic rejects a nullable enum expressed as a "type" array combined with
            // "enum" (e.g. {"type":["string","null"],"enum":[...]}). Rewrite it to the
            // supported anyOf form with a dedicated null branch.
            if( isset( $node['enum'] ) && is_array( $type ) && in_array( 'null', $type, true ) )
            {
                $enum = array_values( array_filter( $node['enum'], fn( $v ) => $v !== null ) );
                $head = array_filter( ['description' => $node['description'] ?? null] );

                return $head + ['anyOf' => [['enum' => $enum], ['type' => 'null']]];
            }

            // Anthropic's strict schema rejects numeric, length and item-count
            // constraints; "minItems" only supports 0 or 1. Drop the unsupported
            // keywords and clamp "minItems" to a supported value.
            unset(
                $node['minimum'], $node['maximum'], $node['exclusiveMinimum'],
                $node['exclusiveMaximum'], $node['multipleOf'],
                $node['minLength'], $node['maxLength'], $node['maxItems']
            );

            if( isset( $node['minItems'] ) && !in_array( $node['minItems'], [0, 1], true ) ) {
                $node['minItems'] = 1;
            }

            if( $type === 'object' || ( is_array( $type ) && in_array( 'object', $type, true ) ) ) {
                $node['additionalProperties'] = false;
            }

            return $node;
        } );
    }


    /**
     * Forces an empty tool_use "input" ([]) back to an object ({}) so the resent message is accepted.
     *
     * @param array<int, array<string, mixed>> $content Assistant content blocks
     * @return array<int, array<string, mixed>> Normalized content blocks
     */
    private function assistantContent( array $content ) : array
    {
        foreach( $content as &$block )
        {
            if( ( $block['type'] ?? '' ) === 'tool_use' && empty( $block['input'] ) ) {
                $block['input'] = (object) [];
            }
        }

        return $content;
    }


    /**
     * Runs the tool loop for the Anthropic Messages API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     * @param callable|null $callback Stream consumer enabling SSE streaming when set
     */
    private function generate( array $messages, array $options, ?callable $callback = null ) : TextResponse
    {
        $allSteps = [];
        $calls = [];
        $citations = [];
        $thinking = null;
        $rateLimit = null;
        $texts = [];
        $result = [];
        $tools = $this->toolsParam();

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( 'claude-opus-4-8' ),
                'messages' => $messages,
                'max_tokens' => $this->maxTokens() ?? 4096,
            ] + $options;

            // Claude 4.6+ supports adaptive thinking via the "thinking"/"effort" options
            // (e.g. ['thinking' => ['type' => 'adaptive'], 'effort' => 'high']). An explicit
            // thinking option wins; otherwise withThinkingBudget() enables a fixed budget.
            if( !isset( $params['thinking'] ) && ( $thinkingBudget = $this->thinkingBudget() ) ) {
                $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => $thinkingBudget];
            }

            if( $system = $this->systemPrompt() ) {
                $params['system'] = $system;
            }

            if( $tools ) {
                $params['tools'] = $tools;

                // Apply the configured tool choice only on the first step so the
                // model can produce a final text answer after calling the tools.
                $toolChoice = $step === 1 ? match( $this->toolChoice() ) {
                    self::REQ => ['type' => 'any'],
                    self::AUTO => ['type' => 'auto'],
                    default => null,
                } : ['type' => 'auto'];

                if( $toolChoice ) {
                    $params['tool_choice'] = $toolChoice;
                }
            }

            if( $callback !== null )
            {
                $params['stream'] = true;
                $result = $this->streamMessage( $params, $callback );
                $rateLimit = $this->streamRateLimit;
            }
            else
            {
                $response = $this->client()->post( 'v1/messages', ['json' => $params] );

                $this->validate( $response );

                $rateLimit = $this->getRateLimit( $response );
                $result = $this->fromJson( $response );
            }

            $stepTexts = [];

            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $result['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( ( $block['type'] ?? null ) === 'text' && isset( $block['text'] ) ) {
                    $stepTexts[] = $block['text'];
                } elseif( ( $block['type'] ?? null ) === 'thinking' && isset( $block['thinking'] ) ) {
                    $thinking = $block['thinking'];
                }

                foreach( $block['citations'] ?? [] as $cit )
                {
                    $citations[] = new \Aimeos\Prisma\Values\Citation(
                        title: $cit['document_title'] ?? null,
                        source: $cit['cited_text'] ?? null,
                    );
                }
            }

            // Keep the last step that produced text so a tool-only final step (e.g. when
            // maxSteps is reached) doesn't discard the model's partial answer.
            $texts = $stepTexts ?: $texts;

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls )
            {
                // Anthropic returns stop_reason "pause_turn" when a long-running server-side
                // tool (e.g. web_search) needs another turn to finish; resend the accumulated
                // assistant content to resume instead of ending with a partial answer.
                if( ( $result['stop_reason'] ?? null ) === 'pause_turn' && $step < $this->maxSteps() ) {
                    $messages[] = ['role' => 'assistant', 'content' => $this->assistantContent( $result['content'] ?? [] )];
                    continue;
                }

                break;
            }

            $toolResults = $this->execTools( $toolCalls, $calls, $callback );
            array_push( $allSteps, ...$toolResults );
            $messages[] = ['role' => 'assistant', 'content' => $this->assistantContent( $result['content'] ?? [] )];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        return $this->result( $result, $allSteps, $texts, $rateLimit );
    }


    /**
     * Builds the TextResponse from an Anthropic API result.
     *
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return TextResponse Text response
     */
    private function result( array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : TextResponse
    {
        $thinking = null;
        $citations = [];

        /** @var array<int, array<string, mixed>> $contentBlocks */
        $contentBlocks = $result['content'] ?? [];

        foreach( $contentBlocks as $block )
        {
            if( ( $block['type'] ?? null ) === 'thinking' && isset( $block['thinking'] ) ) {
                $thinking = $block['thinking'];
            }

            foreach( $block['citations'] ?? [] as $cit )
            {
                $citations[] = new \Aimeos\Prisma\Values\Citation(
                    title: $cit['document_title'] ?? null,
                    source: $cit['cited_text'] ?? null,
                );
            }
        }

        $meta = $result;
        unset( $meta['content'], $meta['usage'] );

        if( $thinking ) {
            $meta['thinking'] = $thinking;
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withCitations( $citations )
            ->withReason( match( $result['stop_reason'] ?? null ) {
                'end_turn', 'stop_sequence' => TextResponse::STOP,
                'tool_use', 'pause_turn' => TextResponse::TOOL,
                'max_tokens' => TextResponse::LENGTH,
                'refusal' => TextResponse::CONTENT,
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


    /**
     * Streams an Anthropic Messages request and rebuilds the non-streaming result.
     *
     * Forwards each text delta to the callback while reassembling the content blocks
     * (text, thinking and tool_use with their accumulated JSON input), then returns a
     * result array shaped like a regular Messages response so the shared tool loop and
     * result builder can reuse it.
     *
     * @param array<string, mixed> $params Request payload with streaming enabled
     * @param callable $callback Text delta consumer
     * @return array<string, mixed> Reassembled API result
     */
    private function streamMessage( array $params, callable $callback ) : array
    {
        /** @var array<string, mixed> $message */
        $message = [];
        /** @var array<int, array<string, mixed>> $blocks */
        $blocks = [];
        /** @var array<int, string> $buffers */
        $buffers = [];
        $stopReason = null;
        /** @var array<string, mixed> $usage */
        $usage = [];

        foreach( $this->streamData( 'v1/messages', $params ) as $event )
        {
            $idx = $event['index'] ?? 0;

            switch( $event['type'] ?? '' )
            {
                case 'message_start':
                    // Seed the result with the message envelope (id, model, role, ...) so
                    // meta() carries the same fields the non-streaming response does.
                    /** @var array<string, mixed> $message */
                    $message = $event['message'] ?? [];
                    /** @var array<string, mixed> $startUsage */
                    $startUsage = $message['usage'] ?? [];
                    $usage = $startUsage + $usage;
                    break;

                case 'content_block_start':
                    $blocks[$idx] = $event['content_block'] ?? [];
                    $buffers[$idx] = '';
                    break;

                case 'content_block_delta':
                    /** @var array<string, mixed> $delta */
                    $delta = $event['delta'] ?? [];

                    switch( $delta['type'] ?? '' )
                    {
                        case 'text_delta':
                            $text = $delta['text'] ?? '';
                            $blocks[$idx]['text'] = ( $blocks[$idx]['text'] ?? '' ) . $text;

                            if( $text !== '' ) {
                                $callback( $text );
                            }
                            break;

                        case 'input_json_delta':
                            $buffers[$idx] = ( $buffers[$idx] ?? '' ) . ( $delta['partial_json'] ?? '' );
                            break;

                        case 'thinking_delta':
                            $blocks[$idx]['thinking'] = ( $blocks[$idx]['thinking'] ?? '' ) . ( $delta['thinking'] ?? '' );
                            break;

                        case 'signature_delta':
                            $blocks[$idx]['signature'] = ( $blocks[$idx]['signature'] ?? '' ) . ( $delta['signature'] ?? '' );
                            break;

                        case 'citations_delta':
                            if( isset( $delta['citation'] ) ) {
                                $blocks[$idx]['citations'][] = $delta['citation'];
                            }
                            break;
                    }
                    break;

                case 'content_block_stop':
                    if( ( $blocks[$idx]['type'] ?? '' ) === 'tool_use' ) {
                        $blocks[$idx]['input'] = $this->jsonArgs( $buffers[$idx] ?? '' );
                    }
                    break;

                case 'message_delta':
                    $stopReason = $event['delta']['stop_reason'] ?? $stopReason;
                    /** @var array<string, mixed> $deltaUsage */
                    $deltaUsage = $event['usage'] ?? [];
                    $usage = $deltaUsage + $usage;
                    break;
            }
        }

        ksort( $blocks );

        $message['content'] = array_values( $blocks );
        $message['stop_reason'] = $stopReason;
        $message['usage'] = $usage;

        return $message;
    }
}
