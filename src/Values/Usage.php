<?php

namespace Aimeos\Prisma\Values;


/**
 * Token usage information from a provider response.
 *
 * Behaves like the usage array it replaced (array access, iteration, count and JSON) while
 * adding typed accessors that normalize the differing token keys each provider reports.
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
class Usage implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    use \Aimeos\Prisma\Concerns\AsArray;


    /**
     * Initializes the usage information.
     *
     * @param array<string, mixed> $data Raw usage map ("used" plus provider-specific keys)
     */
    public function __construct( array $data = [] )
    {
        $this->data = $data;
    }


    /**
     * Returns the number of cached input tokens served from the provider cache.
     *
     * @return int|null Cached input tokens or NULL if not reported
     */
    public function cacheReadTokens() : ?int
    {
        return $this->pick( ['cache_read_input_tokens', 'cacheReadInputTokens', 'cachedContentTokenCount'] )
            ?? $this->nested( ['prompt_tokens_details', 'input_tokens_details'], 'cached_tokens' );
    }


    /**
     * Returns the number of input tokens written to the provider cache.
     *
     * @return int|null Cache creation tokens or NULL if not reported
     */
    public function cacheWriteTokens() : ?int
    {
        return $this->pick( ['cache_creation_input_tokens', 'cacheWriteInputTokens'] );
    }


    /**
     * Returns the number of generated output tokens.
     *
     * @return int|null Output tokens or NULL if not reported
     */
    public function completionTokens() : ?int
    {
        return $this->pick( ['output_tokens', 'completion_tokens', 'candidatesTokenCount', 'outputTokens'] );
    }


    /**
     * Returns the number of prompt input tokens.
     *
     * @return int|null Input tokens or NULL if not reported
     */
    public function promptTokens() : ?int
    {
        return $this->pick( ['input_tokens', 'prompt_tokens', 'promptTokenCount', 'inputTokens'] );
    }


    /**
     * Returns the number of reasoning/thinking tokens.
     *
     * @return int|null Reasoning tokens or NULL if not reported
     */
    public function thoughtTokens() : ?int
    {
        return $this->pick( ['thoughtsTokenCount'] )
            ?? $this->nested( ['completion_tokens_details', 'output_tokens_details'], 'reasoning_tokens' );
    }


    /**
     * Returns the total number of tokens used.
     *
     * Falls back to the sum of prompt and completion tokens for providers that omit a total.
     *
     * @return int|null Total tokens or NULL if not reported
     */
    public function totalTokens() : ?int
    {
        if( ( $total = $this->pick( ['total_tokens', 'totalTokenCount', 'totalTokens'] ) ) !== null ) {
            return $total;
        }

        $prompt = $this->promptTokens();
        $completion = $this->completionTokens();

        return $prompt === null && $completion === null ? null : (int) $prompt + (int) $completion;
    }


    /**
     * Returns the used units (token total for text, credits or cost for media providers).
     *
     * @return float|null Used units or NULL if not reported
     */
    public function used() : ?float
    {
        return is_numeric( $this->data['used'] ?? null ) ? (float) $this->data['used'] : null;
    }


    /**
     * Returns the first numeric value found among the given top-level keys.
     *
     * @param array<int, string> $keys Candidate keys in priority order
     * @return int|null Token count or NULL if none present
     */
    private function pick( array $keys ) : ?int
    {
        foreach( $keys as $key )
        {
            if( is_numeric( $this->data[$key] ?? null ) ) {
                return (int) $this->data[$key];
            }
        }

        return null;
    }


    /**
     * Returns a numeric value nested inside the first present parent detail map.
     *
     * @param array<int, string> $parents Candidate parent keys in priority order
     * @param string $key Key within the parent map
     * @return int|null Token count or NULL if none present
     */
    private function nested( array $parents, string $key ) : ?int
    {
        foreach( $parents as $parent )
        {
            $details = $this->data[$parent] ?? null;

            if( is_array( $details ) && is_numeric( $details[$key] ?? null ) ) {
                return (int) $details[$key];
            }
        }

        return null;
    }
}
