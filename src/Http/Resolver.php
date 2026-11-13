<?php

namespace Aimeos\Prisma\Http;


/**
 * Resolves host names to their public IP addresses.
 *
 * Resolved addresses are cached for a minute, so retries, redirects and polling of asynchronous
 * jobs don't cause new DNS lookups while long-running processes still notice changed addresses.
 * Hosts without public address are cached for a few seconds only, so new hosts are found soon,
 * but a bit longer if the lookup timed out, so an unreachable DNS server doesn't delay every
 * request. The cache is limited to the most
 * recently resolved hosts, so it doesn't grow in long-running processes like Octane workers.
 */
class Resolver
{
    /** Seconds the addresses of a host are used before it's looked up again */
    private const TTL = 60;

    /** Seconds a host without public address is rejected before it's looked up again */
    private const FAIL_TTL = 5;

    /** Seconds a host is rejected before it's looked up again if the lookup timed out */
    private const TIMEOUT_TTL = 15;

    /** Maximum number of cached hosts */
    private const MAX_HOSTS = 100;

    /** Nanoseconds after which a failed DNS lookup is a timeout of the resolver */
    private const TIMEOUT = 1_000_000_000;

    /**
     * IPv6 ranges PHP accepts as global although they aren't globally reachable: local-use NAT64,
     * deprecated site-local, IPv4-compatible and SIIT translated IPv4 addresses, and multicast
     */
    private const LOCAL_RANGES = [
        ["\x00\x64\xff\x9b\x00\x01", 48],
        ["\xfe\xc0", 10],
        ["\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", 96],
        ["\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x00\x00", 96],
        ["\xff", 8],
    ];

    /** @var array<string, array{int, array<int, string>}> Expiry time and public addresses by host */
    private array $resolved = [];


    /**
     * Resolves a hostname to its public IP addresses.
     *
     * Literal IP hosts are validated directly without a DNS lookup.
     *
     * @param string $host Hostname or IP address to resolve
     * @return array<int, string> Public IP addresses, empty if none was found
     */
    public function resolve( string $host ) : array
    {
        if( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return $this->publicIp( $host ) ? [$host] : [];
        }

        if( ( $this->resolved[$host][0] ?? 0 ) > time() ) {
            return $this->resolved[$host][1];
        }

        // removed first, so the order of the entries is the order they were resolved in
        unset( $this->resolved[$host] );

        $start = hrtime( true );
        $ips = array_values( array_filter( $this->lookup( $host ), $this->publicIp( ... ) ) );

        // a timed out lookup means the DNS server doesn't respond, so it's not asked again for every request
        $ttl = $ips ? self::TTL : ( hrtime( true ) - $start > self::TIMEOUT ? self::TIMEOUT_TTL : self::FAIL_TTL );
        $this->resolved[$host] = [time() + $ttl, $ips];

        if( count( $this->resolved ) > self::MAX_HOSTS ) {
            unset( $this->resolved[array_key_first( $this->resolved )] );
        }

        return $ips;
    }


    /**
     * Looks up the IP addresses of a hostname.
     *
     * The system resolver is asked first because it uses the cache of the system and knows
     * hosts from /etc/hosts too. It only returns IPv4 addresses, so the A and AAAA records are
     * only requested from the DNS server for hosts without IPv4 address.
     *
     * @param string $host Hostname to look up
     * @return array<int, string> IPv4 or IPv6 addresses of the host
     */
    protected function lookup( string $host ) : array
    {
        $start = hrtime( true );

        if( ( $ips = $this->system( $host ) ) !== [] ) {
            return $ips;
        }

        // the resolver timed out and would time out again for the DNS records
        if( hrtime( true ) - $start > self::TIMEOUT ) {
            return [];
        }

        return array_values( array_filter( array_map(
            fn( $record ) => $record['ip'] ?? $record['ipv6'] ?? null,
            $this->records( $host ) ?: []
        ) ) );
    }


    /**
     * Returns the IPv4 addresses of a hostname known by the system resolver.
     *
     * @param string $host Hostname to look up
     * @return array<int, string> IPv4 addresses of the host
     */
    protected function system( string $host ) : array
    {
        // warns for invalid host names, which the empty result reports already
        return @gethostbynamel( $host ) ?: [];
    }


    /**
     * Returns the A and AAAA records of a hostname.
     *
     * @param string $host Hostname to look up
     * @return array<int, array<string, mixed>>|false DNS records or FALSE if the resolver failed
     */
    protected function records( string $host ) : array|false
    {
        // warns if the resolver fails, which the return value reports already
        return @dns_get_record( $host, DNS_A + DNS_AAAA );
    }


    /**
     * Tests if an IP address is valid and public.
     *
     * @param string $ip IPv4 or IPv6 address to validate
     * @return bool TRUE if the address is valid and public, FALSE if not
     */
    private function publicIp( string $ip ) : bool
    {
        if( !filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $bin = (string) inet_pton( $ip );

        if( strlen( $bin ) === 16 )
        {
            foreach( self::LOCAL_RANGES as [$prefix, $bits] )
            {
                $bytes = intdiv( $bits, 8 );
                $mask = chr( ( 0xff << ( 8 - $bits % 8 ) ) & 0xff );

                if( !strncmp( $bin, $prefix, $bytes ) && ( $bits % 8 === 0 || ( $bin[$bytes] & $mask ) === ( $prefix[$bytes] & $mask ) ) ) {
                    return false;
                }
            }

            // NAT64 addresses (64:ff9b::/96) are global but embed the IPv4 address the gateway connects to
            if( str_starts_with( $bin, "\x00\x64\xff\x9b" . str_repeat( "\x00", 8 ) ) ) {
                $ip = (string) inet_ntop( substr( $bin, 12 ) );
            }
        }

        // Rejects carrier-grade NAT like the 100.100.100.200 metadata service of Alibaba Cloud too
        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
    }
}
