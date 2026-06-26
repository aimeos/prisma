<?php

namespace Aimeos\Prisma\Testing;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Prisma;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;


/**
 * Drives a Prisma provider against mocked HTTP responses for testing.
 *
 * Wires a real provider to a Guzzle MockHandler so requests never reach a remote API while
 * the provider's own request building, response parsing, tool loop and streaming all run
 * unchanged. Queue responses with response() and inspect what the provider sent with
 * requests() or requested(). Framework-agnostic: assert with whichever test framework you use.
 */
trait InteractsWithPrisma
{
    /** @var array<int, array<string, mixed>> */
    private array $interactsHistory = [];
    private ?MockHandler $interactsHandler = null;
    private ?Provider $interactsProvider = null;


    /**
     * Creates a Prisma provider wired to a mock HTTP handler.
     *
     * @param string $type Provider type (text, image, audio, video)
     * @param string $name Provider name (e.g. openai, anthropic)
     * @param array<string, mixed> $config Provider configuration
     * @return static Same instance for fluent calls
     */
    protected function prisma( string $type, string $name, array $config = [] ) : static
    {
        $this->interactsHandler = new MockHandler();

        $stack = HandlerStack::create( $this->interactsHandler );
        $stack->push( Middleware::history( $this->interactsHistory ) );

        $this->interactsProvider = ( new Prisma( $type ) )
            ->using( $name, $config )
            ->withClientHandler( $stack );

        return $this;
    }


    /**
     * Returns the provider under test.
     *
     * @return Provider Provider instance
     */
    protected function provider() : Provider
    {
        if( !$this->interactsProvider ) {
            throw new \RuntimeException( 'Call prisma() before accessing the provider' );
        }

        return $this->interactsProvider;
    }


    /**
     * Returns the first recorded request/options entry matching the callback.
     *
     * The Guzzle handler entry is returned with the "handler" option removed, so a matcher
     * sees only the request and the meaningful options.
     *
     * @param callable $matcher Match callback: fn(RequestInterface $request, array $options): bool
     * @return array<string, mixed>|null Matching entry (request, options, ...) or null when none matched
     */
    protected function requested( callable $matcher ) : ?array
    {
        foreach( $this->interactsHistory as $entry )
        {
            unset( $entry['options']['handler'] );

            if( $entry['request'] instanceof RequestInterface
                && (bool) $matcher( $entry['request'], $entry['options'] ?? [] ) ) {
                return $entry;
            }
        }

        return null;
    }


    /**
     * Returns all recorded requests in the order they were sent.
     *
     * @return array<int, RequestInterface> Recorded requests
     */
    protected function requests() : array
    {
        $requests = [];

        foreach( $this->interactsHistory as $entry )
        {
            if( $entry['request'] instanceof RequestInterface ) {
                $requests[] = $entry['request'];
            }
        }

        return $requests;
    }


    /**
     * Queues a fake HTTP response for the next request.
     *
     * @param string|array<string, mixed> $body Response body; arrays are JSON-encoded
     * @param array<string, int|string> $headers Response headers
     * @param int $status HTTP status code
     * @param string $reason Reason phrase
     * @return Provider Provider instance for fluent calls
     */
    protected function response( string|array $body = '', array $headers = [], int $status = 200, string $reason = '' ) : Provider
    {
        if( !$this->interactsHandler ) {
            throw new \RuntimeException( 'Call prisma() before queuing a response' );
        }

        if( is_array( $body ) )
        {
            $body = (string) json_encode( $body );
            $headers['Content-Type'] ??= 'application/json';
        }

        $this->interactsHandler->append( new Response( $status, $headers, $body, '1.1', $reason ) );

        return $this->provider();
    }


    /**
     * Queues a fake Server-Sent Events (SSE) streaming response.
     *
     * @param array<int, array<string, mixed>|string> $events Stream events (see Sse::from())
     * @param array<string, int|string> $headers Additional response headers
     * @return Provider Provider instance for fluent calls
     */
    protected function streamResponse( array $events, array $headers = [] ) : Provider
    {
        return $this->response( Sse::from( $events ), ['Content-Type' => 'text/event-stream'] + $headers );
    }
}
