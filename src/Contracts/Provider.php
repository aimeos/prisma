<?php

namespace Aimeos\Prisma\Contracts;

use Aimeos\Prisma\Tools\Concurrency\Concurrency;


interface Provider
{
    /**
     * Create a new provider instance with the given configuration.
     *
     * @param array<string, mixed> $config Configuration options for the provider.
     */
    public function __construct( array $config );


    /**
     * Ensures that the provider has implemented the method.
     *
     * @param string $method Method name
     * @return Provider
     * @throws \Aimeos\Prisma\Exceptions\NotImplementedException
     */
    public function ensure( string $method ) : self;


    /**
     * Tests if the provider has implemented the method.
     *
     * @param string $method Method name
     * @return bool TRUE if implemented, FALSE if absent
     */
    public function has( string $method ) : bool;


    /**
     * Use the model passed by its name.
     *
     * Used if the provider supports more than one model and allows to select
     * between the different models. Otherwise, it's ignored.
     *
     * @param string|null $model Model name
     * @return self Provider interface
     */
    public function model( ?string $model ) : self;


    /**
     * Add client handler for the Guzzle HTTP client.
     *
     * @param \GuzzleHttp\HandlerStack $stack List of Guzzle middleware
     * @return self Provider interface
     */
    public function withClientHandler( \GuzzleHttp\HandlerStack $stack ) : self;


    /**
     * Add options for the Guzzle HTTP client.
     *
     * @param array<string, mixed> $options Associative list of name/value pairs
     * @return self Provider interface
     */
    public function withClientOptions( array $options ) : self;


    /**
     * Configure automatic retry for failed HTTP requests.
     *
     * @param int $maxAttempts Total number of attempts including the initial request
     * @param \Closure|int $delayMs Fixed delay in ms or closure for custom delay
     * @param \Closure|null $when Retry condition callback
     * @return self Provider interface
     */
    public function withClientRetry( int $maxAttempts = 3, \Closure|int $delayMs = 100, ?\Closure $when = null ) : self;


    /**
     * Sets the concurrency strategy for tool execution.
     *
     * @param Concurrency $concurrency Concurrency strategy
     * @return self Provider interface
     */
    public function withConcurrency( Concurrency $concurrency ) : self;


    /**
     * Set the maximum number of tool execution steps.
     *
     * Controls how many times the provider will loop when the model requests
     * tool calls. Default is unlimited.
     *
     * @param int $steps Maximum number of steps
     * @return self Provider interface
     */
    public function withMaxSteps( int $steps ) : self;


    /**
     * Sets the maximum number of output tokens.
     *
     * @param int|null $tokens Maximum output tokens
     * @return self Provider interface
     */
    public function withMaxTokens( ?int $tokens ) : self;


    /**
     * Add a system prompt for the LLM.
     *
     * It may be used by providers supporting system prompts. Otherwise, it's
     * ignored.
     *
     * @param string|null $prompt System prompt
     * @return self Provider interface
     */
    public function withSystemPrompt( ?string $prompt ) : self;


    /**
     * Sets the thinking budget in tokens.
     *
     * @param int|null $budget Thinking budget tokens
     * @return self Provider interface
     */
    public function withThinkingBudget( ?int $budget ) : self;


    /**
     * Set the tool choice strategy.
     *
     * Controls whether the model must use tools, can use tools, or cannot use tools.
     *
     * @param string $choice Tool choice (use AUTO, REQ, NONE constants)
     * @return self Provider interface
     */
    public function withToolChoice( string $choice ) : self;


    /**
     * Add tools for the LLM.
     *
     * Accepts Adapter instances for custom tools and Provider instances
     * for built-in provider tools (e.g. web search, code execution).
     *
     * @param array<int, \Aimeos\Prisma\Tools\Adapter\Adapter> $tools Tool definitions
     * @return self Provider interface
     */
    public function withTools( array $tools ) : self;
}
