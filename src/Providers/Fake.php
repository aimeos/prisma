<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\NotImplementedException;


class Fake extends Base
{
    private array $responses = [];
    private ?Provider $provider = null;


    public function __construct( array $responses )
    {
        $this->responses = $responses;
    }


    public function __call( string $name, array $arguments )
    {
        if( !method_exists( $this->provider, $name ) ) {
            throw new NotImplementedException( sprintf( 'Provider does not implement "%1$s"', $name ) );
        }

        if( empty( $this->responses ) ) {
            throw new NotImplementedException( sprintf( 'No fake response found for "%1$s"', $name ) );
        }

        return array_shift( $this->responses );
    }


    public function ensure( string $method ) : self
    {
        if( !$this->provider ) {
            throw new NotImplementedException( sprintf( 'No provider set for fake, cannot ensure "%1$s"', $method ) );
        }

        $this->provider->ensure( $method );
        return $this;
    }


    public function has( string $method ) : bool
    {
        if( !$this->provider ) {
            throw new NotImplementedException( sprintf( 'No provider set for fake, cannot test "%1$s"', $method ) );
        }

        return $this->provider->has( $method );
    }


    public function use( Provider $provider ) : self
    {
        $this->provider = $provider;
        return $this;
    }
}