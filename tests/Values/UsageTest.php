<?php

namespace Tests\Values;

use Aimeos\Prisma\Values\Usage;
use PHPUnit\Framework\TestCase;


class UsageTest extends TestCase
{
    public function testAnthropicTokens() : void
    {
        $usage = new Usage( [
            'used' => 150.0,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 20,
            'cache_creation_input_tokens' => 10,
        ] );

        $this->assertSame( 100, $usage->promptTokens() );
        $this->assertSame( 50, $usage->completionTokens() );
        $this->assertSame( 150, $usage->totalTokens() );
        $this->assertSame( 20, $usage->cacheReadTokens() );
        $this->assertSame( 10, $usage->cacheWriteTokens() );
        $this->assertSame( 150.0, $usage->used() );
    }


    public function testOpenaiCompletionTokens() : void
    {
        $usage = new Usage( [
            'used' => 300.0,
            'prompt_tokens' => 120,
            'completion_tokens' => 180,
            'total_tokens' => 300,
            'prompt_tokens_details' => ['cached_tokens' => 30],
            'completion_tokens_details' => ['reasoning_tokens' => 40],
        ] );

        $this->assertSame( 120, $usage->promptTokens() );
        $this->assertSame( 180, $usage->completionTokens() );
        $this->assertSame( 300, $usage->totalTokens() );
        $this->assertSame( 30, $usage->cacheReadTokens() );
        $this->assertSame( 40, $usage->thoughtTokens() );
    }


    public function testGeminiTokens() : void
    {
        $usage = new Usage( [
            'used' => 90.0,
            'promptTokenCount' => 60,
            'candidatesTokenCount' => 30,
            'totalTokenCount' => 90,
            'cachedContentTokenCount' => 15,
            'thoughtsTokenCount' => 25,
        ] );

        $this->assertSame( 60, $usage->promptTokens() );
        $this->assertSame( 30, $usage->completionTokens() );
        $this->assertSame( 90, $usage->totalTokens() );
        $this->assertSame( 15, $usage->cacheReadTokens() );
        $this->assertSame( 25, $usage->thoughtTokens() );
    }


    public function testBedrockTokens() : void
    {
        $usage = new Usage( [
            'used' => 80.0,
            'inputTokens' => 50,
            'outputTokens' => 30,
            'totalTokens' => 80,
            'cacheReadInputTokens' => 12,
            'cacheWriteInputTokens' => 8,
        ] );

        $this->assertSame( 50, $usage->promptTokens() );
        $this->assertSame( 30, $usage->completionTokens() );
        $this->assertSame( 80, $usage->totalTokens() );
        $this->assertSame( 12, $usage->cacheReadTokens() );
        $this->assertSame( 8, $usage->cacheWriteTokens() );
    }


    public function testTotalFallsBackToSum() : void
    {
        $usage = new Usage( ['input_tokens' => 100, 'output_tokens' => 50] );

        $this->assertSame( 150, $usage->totalTokens() );
    }


    public function testMissingTokensReturnNull() : void
    {
        $usage = new Usage( ['used' => 1.0] );

        $this->assertNull( $usage->promptTokens() );
        $this->assertNull( $usage->completionTokens() );
        $this->assertNull( $usage->totalTokens() );
        $this->assertNull( $usage->cacheReadTokens() );
        $this->assertNull( $usage->cacheWriteTokens() );
        $this->assertNull( $usage->thoughtTokens() );
        $this->assertSame( 1.0, $usage->used() );
    }


    public function testArrayAccessStaysBackwardCompatible() : void
    {
        $usage = new Usage( ['used' => 8.0, 'input_tokens' => 5] );

        $this->assertSame( 8.0, $usage['used'] );
        $this->assertSame( 5, $usage['input_tokens'] );
        $this->assertNull( $usage['missing'] ?? null );
        $this->assertTrue( isset( $usage['used'] ) );
        $this->assertFalse( isset( $usage['missing'] ) );
    }


    public function testCountableAndIterable() : void
    {
        $usage = new Usage( ['used' => 8.0, 'input_tokens' => 5] );

        $this->assertCount( 2, $usage );
        $this->assertSame( ['used' => 8.0, 'input_tokens' => 5], iterator_to_array( $usage ) );
    }


    public function testSerializesToRawMap() : void
    {
        $data = ['used' => 8.0, 'input_tokens' => 5];
        $usage = new Usage( $data );

        $this->assertSame( $data, $usage->all() );
        $this->assertSame( $data, $usage->jsonSerialize() );
        $this->assertSame( '{"used":8,"input_tokens":5}', json_encode( $usage ) );
    }
}
