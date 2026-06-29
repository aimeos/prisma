<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use GuzzleHttp\HandlerStack;


/**
 * Instruments provider operation calls without adding process-global state.
 */
class Observer implements Provider
{
    private ?string $model = null;


    private string $type;
    private string $name;

    /** @var array<int, \Closure(array<string, mixed>): void> */
    private array $observers;


    /**
     * Creates a provider proxy that emits operation records to observers.
     *
     * @param Provider $provider Wrapped provider
     * @param string $type Provider media type (text, image, audio, video)
     * @param string $name Provider name
     * @param array<int, \Closure(array<string, mixed>): void> $observers Observer callbacks
     */
    public function __construct(
        private Provider $provider,
        string $type,
        string $name,
        array $observers
    ) {
        $this->type = $type;
        $this->name = $name;
        $this->observers = $observers;
    }


    /**
     * Instruments provider operation calls.
     *
     * Dynamic calls are provider capability calls such as write(), stream(), imagine(),
     * transcribe(), etc. Non-stream responses are emitted when the provider returns;
     * stream responses are emitted from their onComplete() callback.
     *
     * @param string $method Provider operation name
     * @param array<int, mixed> $arguments Method arguments
     * @return object Provider response
     * @throws \Throwable Re-throws provider failures after notifying observers
     */
    public function __call( string $method, array $arguments ) : mixed
    {
        $start = hrtime( true );

        try
        {
            $response = $this->provider->{$method}( ...$arguments );

            /** @var object $response */
            if( $method === 'stream' && method_exists( $response, 'onComplete' ) )
            {
                return $response->onComplete(
                    function( object $response, ?\Throwable $error ) use ( $method, $start ) {
                        $meta = method_exists( $response, 'meta' ) ? $response->meta()->all() : [];
                        $usage = method_exists( $response, 'usage' ) ? $response->usage()->all() : [];

                        $this->emit( $method, $start, $error, $meta, $usage );
                    }
                );
            }

            $meta = method_exists( $response, 'meta' ) ? $response->meta()->all() : [];
            $usage = method_exists( $response, 'usage' ) ? $response->usage()->all() : [];

            $this->emit( $method, $start, null, $meta, $usage );
        }
        catch( \Throwable $e )
        {
            $this->emit( $method, $start, $e );
            throw $e;
        }

        return $response;
    }


    /**
     * Ensures that the wrapped provider implements the given method.
     *
     * @param string $method Method name
     * @return static
     */
    public function ensure( string $method ) : static
    {
        $this->provider->ensure( $method );
        return $this;
    }


    /**
     * Tests if the wrapped provider implements the given method.
     *
     * @param string $method Method name
     * @return bool TRUE if implemented, FALSE if absent
     */
    public function has( string $method ) : bool
    {
        return $this->provider->has( $method );
    }


    /**
     * Use the model passed by its name.
     *
     * @param string|null $model Model name
     * @return self Provider interface
     */
    public function model( ?string $model ) : self
    {
        $this->model = $model;
        $this->provider->model( $model );

        return $this;
    }


    /**
     * Add client handler for the Guzzle HTTP client.
     *
     * @param HandlerStack $stack List of Guzzle middleware
     * @return self Provider interface
     */
    public function withClientHandler( HandlerStack $stack ) : self
    {
        $this->provider->withClientHandler( $stack );

        return $this;
    }


    /**
     * Add options for the Guzzle HTTP client.
     *
     * @param array<string, mixed> $options Associative list of name/value pairs
     * @return self Provider interface
     */
    public function withClientOptions( array $options ) : self
    {
        $this->provider->withClientOptions( $options );

        return $this;
    }


    /**
     * Configure automatic retry for failed HTTP requests.
     *
     * @param int $maxAttempts Total number of attempts including the initial request
     * @param \Closure|int $delayMs Fixed delay in ms or closure for custom delay
     * @param \Closure|null $when Retry condition callback
     * @return self Provider interface
     */
    public function withClientRetry( int $maxAttempts = 3, \Closure|int $delayMs = 100, ?\Closure $when = null ) : self
    {
        $this->provider->withClientRetry( $maxAttempts, $delayMs, $when );

        return $this;
    }


    /**
     * Sets prior conversation turns for text providers.
     *
     * @param array<int, array<string, mixed>> $messages Conversation turns
     * @return self Provider interface
     */
    public function withMessages( array $messages ) : self
    {
        $this->provider->withMessages( $messages );

        return $this;
    }


    /**
     * Sets the maximum number of bytes read for a single provider response.
     *
     * @param int $bytes Maximum bytes per response
     * @return self Provider interface
     */
    public function withMaxResponseSize( int $bytes ) : self
    {
        $this->provider->withMaxResponseSize( $bytes );

        return $this;
    }


    /**
     * Sets the concurrency strategy for tool execution.
     *
     * @param Concurrency $concurrency Concurrency strategy
     * @return self Provider interface
     */
    public function withConcurrency( Concurrency $concurrency ) : self
    {
        $this->provider->withConcurrency( $concurrency );

        return $this;
    }


    /**
     * Set the maximum number of tool execution steps.
     *
     * @param int $steps Maximum number of steps
     * @return self Provider interface
     */
    public function withMaxSteps( int $steps ) : self
    {
        $this->provider->withMaxSteps( $steps );

        return $this;
    }


    /**
     * Sets the maximum number of output tokens.
     *
     * @param int|null $tokens Maximum output tokens
     * @return self Provider interface
     */
    public function withMaxTokens( ?int $tokens ) : self
    {
        $this->provider->withMaxTokens( $tokens );

        return $this;
    }


    /**
     * Add a system prompt for the LLM.
     *
     * @param string|null $prompt System prompt
     * @return self Provider interface
     */
    public function withSystemPrompt( ?string $prompt ) : self
    {
        $this->provider->withSystemPrompt( $prompt );

        return $this;
    }


    /**
     * Sets the thinking budget in tokens.
     *
     * @param int|null $budget Thinking budget tokens
     * @return self Provider interface
     */
    public function withThinkingBudget( ?int $budget ) : self
    {
        $this->provider->withThinkingBudget( $budget );

        return $this;
    }


    /**
     * Sets the human-in-the-loop approval callback for tools that require it.
     *
     * @param callable|null $callback Approval resolver: fn(string $name, array<string, mixed> $arguments): bool
     * @return self Provider interface
     */
    public function withToolApproval( ?callable $callback ) : self
    {
        $this->provider->withToolApproval( $callback );

        return $this;
    }


    /**
     * Set the tool choice strategy.
     *
     * Controls whether the model must use tools, can use tools, or cannot use tools.
     *
     * @param string $choice Tool choice (use AUTO, REQUIRED, NONE constants)
     * @return self Provider interface
     */
    public function withToolChoice( string $choice ) : self
    {
        $this->provider->withToolChoice( $choice );

        return $this;
    }


    /**
     * Add tools for the LLM.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> $tools Tool definitions
     * @return self Provider interface
     */
    public function withTools( array $tools ) : self
    {
        $this->provider->withTools( $tools );

        return $this;
    }


    /**
     * Emits an operation record to each observer.
     *
     * @param string $operation Provider operation name
     * @param int $start hrtime(true) timestamp captured before the provider call
     * @param \Throwable|null $error Provider failure, or null when the operation completed successfully
     * @param array<string, mixed> $meta Response metadata
     * @param array<string, mixed> $usage Response usage data
     */
    private function emit( string $operation, int $start, ?\Throwable $error, array $meta = [], array $usage = [] ) : void
    {
        $record = [
            'operation' => $operation,
            'type' => $this->type,
            'provider' => $this->name,
            'model' => $meta['model'] ?? $this->model,
            'durationMs' => ( hrtime( true ) - $start ) / 1e6,
            'error' => $error?->getMessage(),
            'usage' => $usage,
            'meta' => $meta,
        ];

        foreach( $this->observers as $observer )
        {
            try {
                $observer( $record );
            } catch( \Throwable $e ) {
                error_log( 'prisma observer: ' . $e->getMessage() );
            }
        }
    }
}
