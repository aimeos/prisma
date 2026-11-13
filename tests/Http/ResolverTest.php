<?php

namespace Tests\Http;

use Aimeos\Prisma\Http\Resolver;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;


class ResolverTest extends TestCase
{
    public function testResolvesPublicAddresses() : void
    {
        $this->assertSame( ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c'], ( new FakeResolver() )->resolve( 'multi.example.com' ) );
    }


    #[TestWith( ['8.8.8.8', ['8.8.8.8']] )]
    #[TestWith( ['64:ff9b::808:808', ['64:ff9b::808:808']] )]
    #[TestWith( ['127.0.0.1', []] )]
    #[TestWith( ['100.100.100.200', []] )]
    #[TestWith( ['::ffff:a9fe:a9fe', []] )]
    #[TestWith( ['64:ff9b:1::a9fe:a9fe', []] )]
    #[TestWith( ['fec0::1', []] )]
    #[TestWith( ['fefe::1', []] )]
    #[TestWith( ['::a9fe:a9fe', []] )]
    #[TestWith( ['::ffff:0:a9fe:a9fe', []] )]
    #[TestWith( ['ff02::1', []] )]
    #[TestWith( ['2606:4700:4700::1111', ['2606:4700:4700::1111']] )]
    #[TestWith( ['64:ff9b::a9fe:a9fe', []] )]
    public function testValidatesLiteralAddresses( string $ip, array $expected ) : void
    {
        $resolver = new FakeResolver();

        $this->assertSame( $expected, $resolver->resolve( $ip ) );
        $this->assertSame( 0, $resolver->lookups );
    }


    public function testCachesResolvedHosts() : void
    {
        $resolver = new FakeResolver();
        $resolver->resolve( 'api.example.com' );
        $resolver->resolve( 'api.example.com' );

        $this->assertSame( 1, $resolver->lookups );
    }


    public function testExpiresResolvedHosts() : void
    {
        $resolver = new FakeResolver();
        $resolver->resolve( 'api.example.com' );

        $property = new \ReflectionProperty( Resolver::class, 'resolved' );
        $property->setValue( $resolver, array_map( fn( $entry ) => [time(), $entry[1]], $property->getValue( $resolver ) ) );

        $resolver->resolve( 'api.example.com' );

        $this->assertSame( 2, $resolver->lookups );
    }


    public function testCachesUnresolvedHostsBriefly() : void
    {
        $resolver = new FakeResolver();

        $this->assertSame( [], $resolver->resolve( 'unknown.example.com' ) );
        $this->assertSame( [], $resolver->resolve( 'unknown.example.com' ) );
        $this->assertSame( 1, $resolver->lookups );

        $property = new \ReflectionProperty( Resolver::class, 'resolved' );
        $this->assertLessThanOrEqual( time() + 5, $property->getValue( $resolver )['unknown.example.com'][0] );
    }


    public function testCachesTimedOutHostsLonger() : void
    {
        $resolver = new class extends Resolver {
            protected function lookup( string $host ) : array
            {
                usleep( 1_100_000 );
                return [];
            }
        };

        $this->assertSame( [], $resolver->resolve( 'unknown.example.com' ) );

        $property = new \ReflectionProperty( Resolver::class, 'resolved' );
        $expires = $property->getValue( $resolver )['unknown.example.com'][0];

        $this->assertGreaterThan( time() + 5, $expires );
        $this->assertLessThanOrEqual( time() + 15, $expires );
    }


    public function testLimitsCachedHosts() : void
    {
        $resolver = new FakeResolver();

        for( $i = 0; $i <= 100; $i++ ) {
            $resolver->resolve( 'host' . $i . '.example.com' );
        }

        $resolved = ( new \ReflectionProperty( Resolver::class, 'resolved' ) )->getValue( $resolver );

        $this->assertCount( 100, $resolved );
        $this->assertArrayNotHasKey( 'host0.example.com', $resolved );
        $this->assertArrayHasKey( 'host100.example.com', $resolved );
    }


    public function testAsksSystemResolverFirst() : void
    {
        $resolver = new class extends Resolver {
            public function lookup( string $host ) : array
            {
                return parent::lookup( $host );
            }

            protected function system( string $host ) : array
            {
                return ['93.184.215.14'];
            }

            protected function records( string $host ) : array|false
            {
                throw new \LogicException( 'DNS records must not be requested' );
            }
        };

        $this->assertSame( ['93.184.215.14'], $resolver->lookup( 'api.example.com' ) );
    }


    #[TestWith( [0, ['2606:2800:21f:cb07:6820:80da:af6b:8b2c']] )]
    #[TestWith( [1_100_000, []] )]
    public function testSkipsDnsRecordsAfterResolverTimeout( int $delay, array $expected ) : void
    {
        $resolver = new class( $delay ) extends Resolver {
            public function __construct( private int $delay ) {}

            public function lookup( string $host ) : array
            {
                return parent::lookup( $host );
            }

            // a host without IPv4 address or a failing system resolver
            protected function system( string $host ) : array
            {
                usleep( $this->delay );
                return [];
            }

            protected function records( string $host ) : array|false
            {
                return [['type' => 'AAAA', 'ipv6' => '2606:2800:21f:cb07:6820:80da:af6b:8b2c']];
            }
        };

        $this->assertSame( $expected, $resolver->lookup( 'ipv6.example.com' ) );
    }
}
