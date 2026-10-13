<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Video;


class Meta extends Base
{
    use CallsTools;
    use OpenaiApi {
        completionParams as openaiCompletionParams;
        responseParams as openaiResponseParams;
    }


    protected const PROVIDER_TOOL_MAP = [
        'web_search' => ['type' => 'web_search', 'options' => ['search_context_size', 'user_location']],
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.meta.ai' ) );
    }


    /**
     * Builds a Meta chat-completions request.
     *
     * Meta uses max_completion_tokens instead of the deprecated max_tokens and
     * the top-level reasoning_effort parameter.
     *
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Chat messages
     * @param array<string, mixed> $options Provider specific options
     * @param int $step Current step in the tool loop (1-based)
     * @param bool $stream Whether to enable SSE streaming
     * @return array<string, mixed> Request payload
     */
    protected function completionParams( string $defaultModel, array $messages, array $options, int $step, bool $stream ) : array
    {
        $params = $this->openaiCompletionParams( $defaultModel, $messages, $options, $step, $stream );

        if( isset( $params['max_tokens'] ) )
        {
            $params['max_completion_tokens'] = $params['max_tokens'];
            unset( $params['max_tokens'] );
        }

        if( $effort = $this->effort() ) {
            $params['reasoning_effort'] = $effort;
        }

        return $params;
    }


    /**
     * Builds Meta chat content with video support.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Input media files
     * @return array<int, array<string, mixed>> Content blocks
     */
    protected function content( string $prompt, array $files ) : array
    {
        $content = [];

        foreach( $files as $file )
        {
            $type = $file instanceof Video ? 'video_url' : 'image_url';
            $url = $file->url() ?? sprintf( 'data:%s;base64,%s', $file->mimeType(), $file->base64() );

            $content[] = ['type' => $type, $type => ['url' => $url]];
        }

        $content[] = ['type' => 'text', 'text' => $prompt];

        return $content;
    }


    /**
     * Builds a Meta Responses API request with the nested reasoning effort.
     *
     * @param string $defaultModel Default model name
     * @param array<int, array<string, mixed>> $messages Input items
     * @param array<string, mixed> $options Provider specific options
     * @param int $step Current step in the tool loop (1-based)
     * @param bool $stream Whether to enable SSE streaming
     * @return array<string, mixed> Request payload
     */
    protected function responseParams( string $defaultModel, array $messages, array $options, int $step, bool $stream ) : array
    {
        $params = $this->openaiResponseParams( $defaultModel, $messages, $options, $step, $stream );

        if( $effort = $this->effort() ) {
            $params['reasoning'] = ['effort' => $effort] + ( $params['reasoning'] ?? [] );
        }

        return $params;
    }


    /**
     * Meta only supports "auto"; unsupported forced choices are omitted.
     *
     * @return string|null Mapped tool_choice value or null to omit
     */
    protected function toolChoiceParam() : ?string
    {
        return $this->toolChoice() === self::AUTO ? 'auto' : null;
    }


    /**
     * Maps the reasoning settings to a Meta effort level.
     *
     * Muse models reject "none", so disabled reasoning uses "minimal" instead.
     *
     * @return string|null Effort level or null to use the model default
     */
    private function effort() : ?string
    {
        if( $this->reasoningEnabled() === false ) {
            return 'minimal';
        }

        if( $budget = $this->thinkingBudget() ) {
            return match( true ) {
                $budget <= 1024 => 'low',
                $budget <= 8192 => 'medium',
                default => 'high',
            };
        }

        return null;
    }
}
