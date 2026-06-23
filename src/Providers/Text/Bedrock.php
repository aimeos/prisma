<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Bedrock as BedrockBase;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Bedrock extends BedrockBase implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP'] );

        $response = $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $this->schemaPrompt( $prompt, $schema ), $files )]] ),
            $options
        );

        return $response->withStructured( $this->parseJson( $response->text() ) );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'topP'] );

        return $this->generate(
            array_merge( $this->mapMessages(), [['role' => 'user', 'content' => $this->content( $prompt, $files )]] ),
            $options
        );
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
                // Normalize a scalar/null "input" to an empty argument map.
                $input = is_array( $toolUse['input'] ?? null ) ? $toolUse['input'] : [];

                $toolCalls[] = [
                    'id' => $id,
                    'name' => $name,
                    'arguments' => $input,
                ];
            }
        }

        return $toolCalls;
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
     * Forces an empty toolUse "input" ([]) back to an object ({}) so the resent message is accepted.
     *
     * @param array<string, mixed> $message Assistant message with content blocks
     * @return array<string, mixed> Normalized message
     */
    private function assistantContent( array $message ) : array
    {
        if( !isset( $message['content'] ) || !is_array( $message['content'] ) ) {
            return $message;
        }

        foreach( $message['content'] as &$block )
        {
            if( isset( $block['toolUse'] ) && empty( $block['toolUse']['input'] ) ) {
                $block['toolUse']['input'] = (object) [];
            }
        }

        return $message;
    }


    /**
     * Builds content blocks with images and text in Bedrock/Converse format.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Image files
     * @return array<int, array<string, mixed>> Content blocks
     */
    private function content( string $prompt, array $files ) : array
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

        return $content;
    }


    /**
     * Runs the tool loop for the Bedrock Converse API.
     *
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Request options
     */
    private function generate( array $messages, array $options ) : TextResponse
    {
        $model = $this->modelName( 'global.amazon.nova-2-lite-v1:0' );
        $allSteps = [];
        $calls = [];
        $rateLimit = null;
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

            $config = $options;

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

                // Converse only supports forcing tool use via "any"; auto/none are left to
                // the default. Force it only on the first step so the model can produce a
                // final text answer after calling the tools.
                if( $step === 1 && $this->toolChoice() === self::REQ ) {
                    $request['toolConfig']['toolChoice'] = ['any' => (object) []];
                }
            }

            $response = $this->client()->post( $this->baseUrl . '/model/' . $model . '/converse', ['json' => $request] );

            $this->validate( $response );

            $rateLimit = $this->getRateLimit( $response );
            $result = $this->fromJson( $response );
            $stepTexts = [];

            /** @var array<string, mixed> $output */
            $output = $result['output'] ?? [];
            /** @var array<string, mixed> $outputMsg */
            $outputMsg = $output['message'] ?? [];
            /** @var array<int, array<string, mixed>> $contentBlocks */
            $contentBlocks = $outputMsg['content'] ?? [];

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

            $toolResults = $this->execTools( $toolCalls, $calls );
            array_push( $allSteps, ...$toolResults );
            $messages[] = $outputMsg ? $this->assistantContent( $outputMsg ) : ['role' => 'assistant', 'content' => []];
            $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
        }

        return $this->result( $result, $allSteps, $texts, $rateLimit );
    }


    /**
     * Maps the conversation history to Bedrock Converse messages.
     *
     * @return array<int, array<string, mixed>> History messages
     */
    private function mapMessages() : array
    {
        $messages = [];

        foreach( $this->history() as $msg )
        {
            $messages[] = $msg['role'] === 'assistant'
                ? ['role' => 'assistant', 'content' => [['text' => $msg['content']]]]
                : ['role' => 'user', 'content' => $this->content( $msg['content'], $msg['files'] )];
        }

        return $messages;
    }


    /**
     * Builds the TextResponse from a Bedrock Converse API result.
     *
     * @param array<string, mixed> $result API response data
     * @param array<int, \Aimeos\Prisma\Tools\Step> $allSteps Accumulated tool steps
     * @param array<int, string|null> $texts Extracted text content
     * @param \Aimeos\Prisma\Values\RateLimit|null $rateLimit Rate limit information
     * @return TextResponse Text response
     */
    private function result( array $result, array $allSteps, array $texts, ?\Aimeos\Prisma\Values\RateLimit $rateLimit ) : TextResponse
    {
        /** @var array<string, mixed> $output */
        $output = $result['output'] ?? [];
        /** @var array<string, mixed> $outputMsg */
        $outputMsg = $output['message'] ?? [];
        $thinking = null;

        /** @var array<int, array<string, mixed>> $contentBlocks */
        $contentBlocks = $outputMsg['content'] ?? [];

        foreach( $contentBlocks as $block )
        {
            if( isset( $block['reasoningContent']['reasoningText']['text'] ) ) {
                $thinking = $block['reasoningContent']['reasoningText']['text'];
            }
        }

        $meta = $result;
        unset( $meta['output'], $meta['usage'] );

        if( $thinking ) {
            $meta['thinking'] = $thinking;
        }

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];

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
}
