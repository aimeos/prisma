<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Bedrock as BedrockBase;
use Aimeos\Prisma\Responses\TextResponse;


class Bedrock extends BedrockBase implements Write
{


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $content = [];

        foreach( $files as $file )
        {
            $content[] = [
                'image' => [
                    'format' => explode( '/', (string) $file->mimeType() )[1] ?? 'png',
                    'source' => [
                        'bytes' => $file->base64()
                    ]
                ]
            ];
        }

        $content[] = ['text' => $prompt];

        $messages = [['role' => 'user', 'content' => $content]];

        return $this->generate( $messages, $options );
    }


    /**
     * Builds tool result messages in Bedrock/Converse format.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Step> $results Tool execution results
     * @return array<int, array<string, mixed>> Formatted tool result messages
     */
    protected function toolResults( array $results ) : array
    {
        $content = [];

        foreach( $results as $step )
        {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $step->id(),
                    'content' => [['text' => $step->result()]],
                ],
            ];
        }

        return [['role' => 'user', 'content' => $content]];
    }


    /**
     * Builds the tools parameter in Bedrock/Converse format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $tools = [];

        foreach( $this->tools() as $tool )
        {
            $tools[] = [
                'toolSpec' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'inputSchema' => [
                        'json' => $tool->schema()->toArray(),
                    ],
                ],
            ];
        }

        return $tools;
    }


    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     */
    private function generate( array $messages, array $options ) : TextResponse
    {
        $model = $this->modelName( 'amazon.nova-pro-v1:0' );
        $allSteps = [];
        $rateLimit = [];
        $texts = [];
        $result = [];

        for( $step = 1; $step <= $this->maxSteps(); $step++ )
        {
            $request = [
                'messages' => $messages,
            ];

            if( $system = $this->systemPrompt() ) {
                $request['system'] = [['text' => $system]];
            }

            $config = $this->allowed( $options, ['temperature', 'topP'] );

            if( $this->maxTokens() ) {
                $config['maxTokens'] = $this->maxTokens();
            }

            if( !empty( $config ) ) {
                $request['inferenceConfig'] = $config;
            }

            if( $this->thinkingBudget() ) {
                $request['performanceConfig'] = ['reasoningBudgetTokens' => $this->thinkingBudget()];
            }

            if( $tools = $this->toolsParam() ) {
                $request['toolConfig'] = ['tools' => $tools];
            }

            $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/converse', ['json' => $request] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $texts = [];

            /** @var array<string, mixed> $output */
            $output = $result['output'] ?? [];
            /** @var array<string, mixed> $outputMsg */
            $outputMsg = $output['message'] ?? [];
            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $outputMsg['content'] ?? [];

            foreach( $contentBlocks as $block )
            {
                if( isset( $block['reasoningContent']['reasoningText']['text'] ) ) {
                    $thinking = $block['reasoningContent']['reasoningText']['text'];
                } elseif( $text = $block['text'] ?? null ) {
                    $texts[] = $text;
                }
            }

            $toolCalls = $this->parseToolCalls( $result );

            if( !$toolCalls ) {
                break;
            }

            $toolResults = $this->execTools( $toolCalls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = $outputMsg ?: ['role' => 'assistant', 'content' => []];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        if( $thinking ?? null ) {
            $meta['thinking'] = $thinking;
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

        /** @var array<int, string|null> $texts */
        return TextResponse::fromTexts( $texts )
            ->withSteps( $allSteps )
            ->withReason( match( $result['stopReason'] ?? null ) {
                'end_turn', 'stop_sequence' => TextResponse::STOP,
                'tool_use' => TextResponse::TOOL,
                'max_tokens' => TextResponse::LENGTH,
                'content_filtered' => TextResponse::CONTENT,
                default => TextResponse::UNKNOWN,
            } )
            ->withUsage(
                ( isset( $usage['inputTokens'] ) && is_numeric( $usage['inputTokens'] ) ? (float) $usage['inputTokens'] : 0 )
                + ( isset( $usage['outputTokens'] ) && is_numeric( $usage['outputTokens'] ) ? (float) $usage['outputTokens'] : 0 ),
                $usage,
            )
            ->withRateLimit( $rateLimit )
            ->withMeta( $meta );
    }


    /**
     * Parses tool calls from Bedrock/Converse API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function parseToolCalls( array $result ) : array
    {
        $toolCalls = [];

        /** @var array<string, mixed> $output */
        $output = $result['output'] ?? [];
        /** @var array<string, mixed> $outputMsg */
        $outputMsg = $output['message'] ?? [];
        /** @var array<int, array<string, mixed>> $contentBlocks */
        $contentBlocks = $outputMsg['content'] ?? [];

        foreach( $contentBlocks as $block )
        {
            if( isset( $block['toolUse'] ) ) {
                /** @var array<string, mixed> $toolUse */
                $toolUse = $block['toolUse'];
                /** @var string|null $id */
                $id = $toolUse['toolUseId'] ?? null;
                /** @var string $name */
                $name = $toolUse['name'] ?? '';
                /** @var array<string, mixed> $input */
                $input = $toolUse['input'] ?? [];

                $toolCalls[] = [
                    'id' => $id,
                    'name' => $name,
                    'arguments' => $input,
                ];
            }
        }

        return $toolCalls;
    }
}
