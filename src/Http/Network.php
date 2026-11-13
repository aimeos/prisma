<?php

namespace Aimeos\Prisma\Http;

use Closure;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;


/**
 * Network access of a provider and the files it returns.
 *
 * Holds the Guzzle handler stack, whether internal hosts are allowed and the resolver of the
 * hosts. Providers pass it to their file responses, which hand a copy without the middleware of
 * the provider to the files they create, so downloads of results use the same handler and host
 * restrictions as the API requests, but never the credentials of the provider. Instances are immutable except for the DNS cache of their
 * resolver, which copies share because it only contains checked public addresses. If only
 * public hosts are allowed, the requests are pinned to the checked addresses by the Pin middleware.
 *
 * Without a handler stack set via withHandler(), copies with the same host restriction share
 * a default stack, so its cURL handles keep connections open across requests of the provider
 * and its files. Copies with a different host restriction get their own one: cURL reuses open
 * connections by host name, so a connection to an internal address must never be reused by a
 * copy which only allows public addresses. Copies of a stack set via withHandler() share its
 * handler, so their cURL connections are closed after each request if internal hosts are allowed.
 */
class Network
{
    private Resolver $resolver;
    private HandlerStack $default;
    private ?HandlerStack $stack = null;
    private bool $strict = true;


    /**
     * Initializes the instance.
     *
     * @param Resolver|null $resolver Resolver of host names to public IP addresses
     */
    public function __construct( ?Resolver $resolver = null )
    {
        $this->resolver = $resolver ?? new Resolver();
        $this->default = HandlerStack::create();
    }


    /**
     * Keeps the host restriction only because handler stacks contain closures and resolved
     * addresses expire.
     *
     * @return array<string, mixed> Properties to serialize
     */
    public function __serialize() : array
    {
        return ['strict' => $this->strict];
    }


    /**
     * Restores the host restriction.
     *
     * @param array<string, mixed> $data Serialized properties
     */
    public function __unserialize( array $data ) : void
    {
        $this->__construct();
        $this->strict = (bool) ( $data['strict'] ?? true );
    }


    /**
     * Returns a new handler stack with the pin as innermost middleware if internal hosts aren't allowed.
     *
     * The handler stack set via withHandler() or the default stack is copied, so the middleware is
     * never added to a stack shared with other clients.
     *
     * @param callable|null $middleware Additional middleware like retries, added before the pin
     * @return HandlerStack Guzzle handler stack
     */
    public function handler( ?callable $middleware = null ) : HandlerStack
    {
        $handler = clone ( $this->stack ?? $this->default );

        if( $middleware ) {
            $handler->push( $middleware );
        }

        // Pushed last, so it's the innermost middleware and checks every redirect and retry too
        if( $this->strict ) {
            $handler->push( new Pin( $this->resolver, $this->base( $handler ) ), 'prisma_pin' );
        } elseif( $this->stack ) {
            $handler->push( $this->unshared(), 'prisma_unshared' );
        }

        return $handler;
    }


    /**
     * Returns a copy using the given Guzzle handler stack.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return static New instance
     */
    public function withHandler( HandlerStack $stack ) : static
    {
        // copied, so later changes of the caller's stack don't affect this instance
        $network = clone $this;
        $network->stack = clone $stack;

        return $network;
    }


    /**
     * Returns a copy which allows or denies internal hosts and proxies.
     *
     * @param bool $allow TRUE to allow internal hosts and proxies, FALSE to allow public hosts only
     * @return static New instance
     */
    public function withInternalHosts( bool $allow = true ) : static
    {
        $network = clone $this;

        // connections opened by one host restriction must not be reused by the other one
        if( $network->strict === $allow ) {
            $network->default = HandlerStack::create();
        }

        $network->strict = !$allow;

        return $network;
    }


    /**
     * Returns a copy whose handler stack contains the handler of the stack set via withHandler()
     * but none of its middleware, which may add credentials to the requests.
     *
     * @return static New instance
     */
    public function withoutMiddleware() : static
    {
        $network = clone $this;

        if( $this->stack ) {
            $network->stack = HandlerStack::create( $this->base( $this->stack ) );
        }

        return $network;
    }


    /**
     * Returns the handler of the handler stack.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return callable|null Handler of the stack or NULL if none is set
     */
    private function base( HandlerStack $stack ) : ?callable
    {
        // HandlerStack has no getter for its handler
        $handler = ( new \ReflectionProperty( HandlerStack::class, 'handler' ) )->getValue( $stack );
        return is_callable( $handler ) ? $handler : null;
    }


    /**
     * Returns the middleware which closes cURL connections after the request, so copies sharing
     * the handler which only allow public addresses can't reuse a connection to an internal one.
     *
     * @return Closure Guzzle middleware
     */
    private function unshared() : Closure
    {
        return fn( callable $next ) => function( RequestInterface $request, array $options ) use ( $next ) {
            $options['curl'][CURLOPT_FORBID_REUSE] = true;
            return $next( $request, $options );
        };
    }
}
