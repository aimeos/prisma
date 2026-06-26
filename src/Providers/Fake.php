<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Exceptions\PrismaException;


class Fake extends Base
{
    /** @var array<int, mixed> */
    private array $responses = [];

    /** @var array<int, array{method: string, arguments: array<int, mixed>}> */
    private array $calls = [];

    private ?Provider $provider = null;


    /**
     * Creates a new fake provider with the given responses.
     *
     * @param array<int, mixed> $responses Responses returned in call order
     */
    public function __construct( array $responses )
    {
        $this->responses = $responses;
    }


    /**
     * Handles calls to methods not implemented by the fake provider.
     *
     * Records the call, then returns the next queued response. A queued Throwable is
     * thrown instead of returned so a provider error can be simulated for that call.
     *
     * @param string $method Method name
     * @param array<int, mixed> $arguments Method arguments
     * @return mixed Next queued response
     * @throws NotImplementedException If no provider is set, the method is unknown, or no response is queued
     * @throws \Throwable If the next queued response is a Throwable
     */
    public function __call( string $method, array $arguments ) : mixed
    {
        if( !$this->provider ) {
            throw new NotImplementedException( sprintf( 'No provider set for fake, cannot call "%1$s"', $method ) );
        }

        if( !method_exists( $this->provider, $method ) ) {
            throw new NotImplementedException( sprintf( 'Provider does not implement "%1$s"', $method ) );
        }

        $this->calls[] = ['method' => $method, 'arguments' => $arguments];

        if( empty( $this->responses ) ) {
            throw new NotImplementedException( sprintf( 'No fake response found for "%1$s"', $method ) );
        }

        $response = array_shift( $this->responses );

        if( $response instanceof \Throwable ) {
            throw $response;
        }

        return $response;
    }


    /**
     * Asserts that a matching call was recorded, throwing otherwise.
     *
     * @param string $method Expected method name
     * @param callable|null $matcher Optional argument matcher: fn(array $arguments): bool
     * @throws PrismaException If no matching call was recorded
     */
    public function assertCalled( string $method, ?callable $matcher = null ) : void
    {
        foreach( $this->calls as $call )
        {
            if( $call['method'] === $method && ( $matcher === null || (bool) $matcher( $call['arguments'] ) ) ) {
                return;
            }
        }

        throw new PrismaException( sprintf( 'No matching call to "%1$s" was recorded', $method ) );
    }


    /**
     * Returns whether the given method was called at least once.
     *
     * @param string $method Method name
     * @return bool TRUE if the method was called, FALSE otherwise
     */
    public function called( string $method ) : bool
    {
        foreach( $this->calls as $call )
        {
            if( $call['method'] === $method ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Returns the recorded calls in invocation order.
     *
     * @return array<int, array{method: string, arguments: array<int, mixed>}> Recorded calls
     */
    public function calls() : array
    {
        return $this->calls;
    }


    /**
     * Ensures that the given method is implemented by the provider.
     *
     * @param string $method Method name
     * @return static
     * @throws NotImplementedException If no provider is set
     */
    public function ensure( string $method ) : static
    {
        if( !$this->provider ) {
            throw new NotImplementedException( sprintf( 'No provider set for fake, cannot ensure "%1$s"', $method ) );
        }

        $this->provider->ensure( $method );
        return $this;
    }


    /**
     * Checks whether the given method is implemented by the provider.
     *
     * @param string $method Method name
     * @return bool TRUE if the method is implemented, FALSE otherwise
     */
    public function has( string $method ) : bool
    {
        if( !$this->provider ) {
            throw new NotImplementedException( sprintf( 'No provider set for fake, cannot test "%1$s"', $method ) );
        }

        return $this->provider->has( $method );
    }


    /**
     * Sets the provider to use for method checks.
     *
     * @param Provider $provider The provider to use
     * @return static
     */
    public function use( Provider $provider ) : static
    {
        $this->provider = $provider;
        return $this;
    }
}
