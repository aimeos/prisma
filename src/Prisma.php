<?php

namespace Aimeos\Prisma;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Fake;
use Aimeos\Prisma\Providers\Observer;


class Prisma
{
    private static ?Fake $fake = null;

    /** @var array<int, \Closure(array<string, mixed>): void> */
    private array $observers = [];

    private string $type;


    /**
     * Initializes the Prisma instance for the given media type.
     *
     * @param string $type Media type (text, image, audio, video)
     */
    public function __construct( string $type )
    {
        $this->type = $type;
    }


    /**
     * Creates a new instance for audio providers.
     *
     * @return self New Prisma instance for audio
     */
    public static function audio() : self
    {
        return new self( 'audio' );
    }


    /**
     * Sets up a fake provider for testing.
     *
     * Each call made through using() returns the next queued response in order; a queued
     * Throwable is thrown to simulate a provider error. The returned Fake records the calls
     * it received, so tests can assert against them (assertCalled(), calls()). The fake is
     * process-global state - call reset() in tearDown() so it does not leak into other tests.
     *
     * @param array<int, mixed> $responses Fake responses to return, in call order
     * @return Fake Fake provider recording the calls it receives
     */
    public static function fake( array $responses = [] ) : Fake
    {
        return self::$fake = new Fake( $responses );
    }


    /**
     * Creates a new instance for image providers.
     *
     * @return self New Prisma instance for image
     */
    public static function image() : self
    {
        return new self( 'image' );
    }


    /**
     * Adds a request-scoped observer for provider operations.
     *
     * @param callable $callback Observer callback: fn(array{
     *     operation: string,
     *     type: string,
     *     provider: string,
     *     model: string|null,
     *     durationMs: float,
     *     error: string|null,
     *     usage: array<string, mixed>,
     *     meta: array<string, mixed>
     * }): void
     * @return self Same Prisma instance with the observer attached
     */
    public function observe( callable $callback ) : self
    {
        $this->observers[] = \Closure::fromCallable( $callback );

        return $this;
    }


    /**
     * Clears the fake provider set up by fake().
     *
     * Call this in tearDown() so the process-global fake does not leak into later tests.
     */
    public static function reset() : void
    {
        self::$fake = null;
    }


    /**
     * Checks if a provider supports the given method.
     *
     * @param string $type Media type (text, image, audio, video)
     * @param string $provider Provider name
     * @param string $method Method name to check
     * @param array<string, mixed> $config Provider configuration
     * @return bool True if the provider supports the method
     */
    public static function supports( string $type, string $provider, string $method, array $config ) : bool
    {
        try {
            return ( new self( $type ) )->using( $provider, $config )->has( $method );
        } catch( PrismaException ) {
            return false;
        }
    }


    /**
     * Creates a new instance for text providers.
     *
     * @return self New Prisma instance for text
     */
    public static function text() : self
    {
        return new self( 'text' );
    }


    /**
     * Creates a new instance for the given media type.
     *
     * @param string $type Media type (text, image, audio, video)
     * @return self New Prisma instance
     */
    public static function type( string $type ) : self
    {
        return new self( $type );
    }


    /**
     * Returns a provider instance for the given name.
     *
     * @param string|null $name Provider name
     * @param array<string, mixed> $config Provider configuration
     * @return Provider Provider instance
     * @throws PrismaException If no provider name is given
     * @throws NotImplementedException If the provider class does not exist or is invalid
     */
    public function using( ?string $name, array $config = [] ) : Provider
    {
        if( !$name ) {
            throw new PrismaException( 'No provider name given' );
        }

        // Restrict the type and name to a safe character set before interpolating them into a
        // class name, so neither can smuggle a backslash to reach a class outside the intended
        // Providers\{Type} namespace or probe the autoloader with attacker-controlled input. The
        // rejected value is deliberately not echoed back into the message.
        if( !preg_match( '/^[A-Za-z0-9_]+$/', $this->type ) || !preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
            throw new NotImplementedException( 'Provider type or name contains invalid characters' );
        }

        $classname = '\\Aimeos\\Prisma\\Providers\\' . ucfirst( $this->type ) . '\\' . ucfirst( $name );

        if( !class_exists( $classname ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" not found', $classname ) );
        }

        $provider = new $classname( $config );

        if( !( $provider instanceof Provider ) ) {
            throw new NotImplementedException( sprintf( 'Provider "%1$s" does not implement "%2$s"', $classname, Provider::class ) );
        }

        $provider = self::$fake ? self::$fake->use( $provider ) : $provider;

        return $this->observers ? new Observer( $provider, $this->type, $name, $this->observers ) : $provider;
    }


    /**
     * Creates a new instance for video providers.
     *
     * @return self New Prisma instance for video
     */
    public static function video() : self
    {
        return new self( 'video' );
    }
}
