<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Anthropic extends Base
{
    use CallsTools { mapProviderTools as baseMapProviderTools; }


    /** @var array<string, array<string, mixed>> */
    private static array $providerToolMap = [
        'web_search' => ['type' => 'web_search_20250305', 'name' => 'web_search', 'options' => ['allowed_domains', 'blocked_domains', 'user_location']],
        'code_execution' => ['type' => 'code_execution_20250825', 'name' => 'code_execution', 'options' => []],
        'web_fetch' => ['type' => 'web_fetch_20250910', 'name' => 'web_fetch', 'options' => []],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'x-api-key', $this->cfg( $config, 'api_key' ) );
        $this->header( 'anthropic-version', '2023-06-01' );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://api.anthropic.com' ) );
    }


    /**
     * Builds content blocks with images and text in Anthropic format.
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
            $url = $file->url();

            // A URL source doesn't need the mime, so an undeterminable type just defaults
            // to an image block (the long-standing default) instead of failing; a base64
            // source needs the mime for media_type, where a genuine mismatch must surface.
            try {
                $mime = $file->mimeType();
            } catch( PrismaException $e ) {
                if( !$url ) {
                    throw $e;
                }
                $mime = null;
            }

            // An explicit image mime is authoritative; otherwise a PDF mime, or a ".pdf" URL
            // path when the type can't be probed, maps to a document block without fetching
            // the whole file.
            $image = $mime !== null && str_starts_with( $mime, 'image/' );
            $document = !$image && ( $mime === 'application/pdf'
                || ( $url !== null && str_ends_with( strtolower( (string) parse_url( $url, PHP_URL_PATH ) ), '.pdf' ) ) );

            $content[] = [
                'type' => $document ? 'document' : 'image',
                'source' => $url
                    ? ['type' => 'url', 'url' => $url]
                    : ['type' => 'base64', 'media_type' => $mime, 'data' => $file->base64()],
            ];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $content;
    }


    /**
     * Maps the conversation history to Anthropic messages.
     *
     * @return array<int, array<string, mixed>> History messages
     */
    protected function mapMessages() : array
    {
        $messages = [];

        foreach( $this->history() as $msg )
        {
            $messages[] = $msg['role'] === 'assistant'
                ? ['role' => 'assistant', 'content' => $msg['content']]
                : ['role' => 'user', 'content' => $this->content( $msg['content'], $msg['files'] )];
        }

        return $messages;
    }


    /**
     * Builds tool result messages in Anthropic format.
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
                'type' => 'tool_result',
                'tool_use_id' => $step->id(),
                'content' => $step->result(),
            ];
        }

        return [['role' => 'user', 'content' => $content]];
    }


    /**
     * Builds the tools parameter in Anthropic format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $tools = [];

        foreach( $this->tools() as $tool )
        {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->schema()->toArray(),
            ];
        }

        return array_merge( $tools, $this->mapProviderTools( self::$providerToolMap ) );
    }


    /**
     * Parses tool calls from Anthropic API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function parseToolCalls( array $result ) : array
    {
        $toolCalls = [];

        /** @var array<int, array<string, mixed>> $content */
        $content = $result['content'] ?? [];

        foreach( $content as $block )
        {
            if( ( $block['type'] ?? '' ) === 'tool_use' ) {
                /** @var string|null $id */
                $id = $block['id'] ?? null;
                /** @var string $name */
                $name = $block['name'] ?? '';
                /** @var array<string, mixed> $input */
                $input = $block['input'] ?? [];

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
     * @param array<string, array<string, mixed>> $map
     * @return array<int, array<string, mixed>>
     */
    protected function mapProviderTools( array $map ) : array
    {
        $tools = $this->baseMapProviderTools( $map );

        foreach( $this->providerTools() as $tool )
        {
            if( $tool->limit() < PHP_INT_MAX )
            {
                foreach( $tools as &$entry )
                {
                    if( isset( $map[$tool->name()] ) && ( $entry['type'] ?? '' ) === ( $map[$tool->name()]['type'] ?? '' ) ) {
                        $entry['max_uses'] = $tool->limit();
                    }
                }
            }
        }

        return $tools;
    }
}
