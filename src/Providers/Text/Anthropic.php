<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Anthropic as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Anthropic extends Base implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );
        $options['output_config'] = [
            'format' => [
                'type' => 'json_schema',
                'schema' => $this->jsonSchema( $schema->toArray() ),
            ],
        ];

        $messages = [['role' => 'user', 'content' => $this->content( $prompt, $files )]];

        $response = $this->generate( $messages, $options );
        $structured = json_decode( $response->text() ?? '', true ) ?: [];

        return $response->withStructured( $structured );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['citations', 'temperature', 'top_p', 'top_k'] );

        return $this->generate(
            [['role' => 'user', 'content' => $this->content( $prompt, $files )]],
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
        $type = $schema['type'] ?? null;

        // Anthropic rejects a nullable enum expressed as a "type" array combined with
        // "enum" (e.g. {"type":["string","null"],"enum":[...]}). Rewrite it to the
        // supported anyOf form with a dedicated null branch.
        if( isset( $schema['enum'] ) && is_array( $type ) && in_array( 'null', $type, true ) )
        {
            $enum = array_values( array_filter( $schema['enum'], fn( $v ) => $v !== null ) );
            $head = array_filter( ['description' => $schema['description'] ?? null] );

            return $head + ['anyOf' => [['enum' => $enum], ['type' => 'null']]];
        }

        // Anthropic's strict schema rejects numeric, length and item-count
        // constraints; "minItems" only supports 0 or 1. Drop the unsupported
        // keywords and clamp "minItems" to a supported value.
        unset(
            $schema['minimum'], $schema['maximum'], $schema['exclusiveMinimum'],
            $schema['exclusiveMaximum'], $schema['multipleOf'],
            $schema['minLength'], $schema['maxLength'], $schema['maxItems']
        );

        if( isset( $schema['minItems'] ) && !in_array( $schema['minItems'], [0, 1], true ) ) {
            $schema['minItems'] = 1;
        }

        if( $type === 'object' || ( is_array( $type ) && in_array( 'object', $type, true ) ) ) {
            $schema['additionalProperties'] = false;
        }

        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
            $schema['properties'] = array_map( fn( array $prop ) => $this->jsonSchema( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->jsonSchema( $schema['items'] );
        }

        if( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
            $schema['anyOf'] = array_map( fn( array $sub ) => $this->jsonSchema( $sub ), $schema['anyOf'] );
        }

        if( isset( $schema['$defs'] ) && is_array( $schema['$defs'] ) ) {
            $schema['$defs'] = array_map( fn( array $sub ) => $this->jsonSchema( $sub ), $schema['$defs'] );
        }

        return $schema;
    }


    /**
     * Runs the tool loop for the Anthropic Messages API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Pre-filtered request options
     */
    private function generate( array $messages, array $options ) : TextResponse
    {
        $allSteps = [];
        $calls = [];
        $citations = [];
        $thinking = null;
        $rateLimit = null;
        $texts = [];
        $result = [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( 'claude-opus-4-8' ),
                'messages' => $messages,
                'max_tokens' => $this->maxTokens() ?? 4096,
            ] + $options;

            if( $thinkingBudget = $this->thinkingBudget() ) {
                $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => $thinkingBudget];
            }

            if( $system = $this->systemPrompt() ) {
                $params['system'] = $system;
            }

            if( $tools = $this->toolsParam() ) {
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

            $response = $this->client()->post( 'v1/messages', ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $texts = [];

            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $result['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( ( $block['type'] ?? null ) === 'text' && isset( $block['text'] ) ) {
                    $texts[] = $block['text'];
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

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls, $calls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = ['role' => 'assistant', 'content' => $this->assistantContent( $result['content'] ?? [] )];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        return $this->result( $result, $allSteps, $texts, $rateLimit );
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
                'tool_use' => TextResponse::TOOL,
                'max_tokens' => TextResponse::LENGTH,
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
