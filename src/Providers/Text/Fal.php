<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Stream;
use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Vectorize;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Openrouter as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Aimeos\Prisma\Schema\Schema;


/**
 * Language models hosted by fal.ai.
 *
 * fal provides the models of OpenRouter by an OpenAI compatible API, so model names
 * are OpenRouter model IDs, e.g. "anthropic/claude-sonnet-5".
 */
class Fal extends Base implements Stream, Structure, Vectorize, Write
{
    // server tools of OpenRouter aren't documented for the fal API
    protected const PROVIDER_TOOL_MAP = [];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $url = $this->config( $config, 'url', 'https://fal.run/openrouter/router/openai/v1' );

        $this->header( 'Authorization', 'Key ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( rtrim( $url, '/' ) . '/' );
    }


    public function stream( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'reasoning'] );
        $messages = $this->messages( $this->content( $prompt, $files ) );

        return $this->streamCompletions( 'chat/completions', 'openai/gpt-6-luna', $messages, $options );
    }


    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $mode = $options['mode'] ?? null;
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'reasoning'] );

        return $this->structuredCompletions(
            'chat/completions', 'openai/gpt-6-luna',
            $prompt, $files, $schema, $options, $mode
        );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['encoding_format', 'user'] );

        return $this->embeddings( 'embeddings', 'openai/text-embedding-3-small', $texts, $size, $options );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty', 'reasoning'] );

        return $this->completions(
            'chat/completions', 'openai/gpt-6-luna',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
