<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Anthropic as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Anthropic extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $file->mimeType(),
                    'data' => $file->base64()
                ]
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        $messages = [[
            'role' => 'user',
            'content' => $content
        ]];

        return $this->generate( $messages, $options );
    }


    /**
     * Generates a text response from the API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Request options
     */
    private function generate( array $messages, array $options ) : TextResponse
    {
        $thinkingBudget = $options['thinking_budget'] ?? null;
        unset( $options['thinking_budget'] );

        $allSteps = [];
        $thinking = null;
        $rateLimit = [];
        $texts = [];
        $result = [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $params = [
                'model' => $this->modelName( 'claude-opus-4-7' ),
                'messages' => $messages,
                'max_tokens' => $options['max_tokens'] ?? 4096,
            ] + $this->allowed( $options, ['temperature', 'top_p', 'top_k'] );

            if( $thinkingBudget ) {
                $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => $thinkingBudget];
            }

            if( $system = $this->systemPrompt() ) {
                $params['system'] = $system;
            }

            if( $tools = $this->toolsParam() ) {
                $params['tools'] = $tools;

                $toolChoice = match( $this->toolChoice() ) {
                    'required' => ['type' => 'any'],
                    'auto' => ['type' => 'auto'],
                    default => null,
                };

                if( $toolChoice ) {
                    $params['tool_choice'] = $toolChoice;
                }
            }

            $response = $this->client()->post( 'v1/messages', ['json' => $params] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $texts = [];
            $thinking = null;

            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $result['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( ( $block['type'] ?? null ) === 'text' && isset( $block['text'] ) ) {
                    $texts[] = $block['text'];
                } elseif( ( $block['type'] ?? null ) === 'thinking' && isset( $block['thinking'] ) ) {
                    $thinking = $block['thinking'];
                }
            }

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = ['role' => 'assistant', 'content' => $result['content']];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        $meta = $result;
        unset( $meta['content'], $meta['usage'] );

        if( $thinking ) {
            $meta['thinking'] = $thinking;
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        /** @var array<int, string|null> $texts */
        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
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
