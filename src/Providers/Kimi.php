<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Kimi extends Base
{
    use CallsTools;
    use OpenaiApi {
        completionParams as openaiCompletionParams;
    }


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.moonshot.ai' ) );
    }


    /**
     * Builds a Kimi chat-completions request.
     *
     * Kimi K3 uses max_completion_tokens and exposes the reasoning effort levels
     * low/high/max instead of the generic OpenAI low/medium/high mapping.
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

        if( $budget = $this->thinkingBudget() ) {
            $params['reasoning_effort'] = match( true ) {
                $budget <= 1024 => 'low',
                $budget <= 8192 => 'high',
                default => 'max',
            };
        }

        return $params;
    }
}
