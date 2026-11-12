<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Resolving of host names to IP addresses which are allowed to connect to.
 */
trait ResolvesHosts
{
    /**
     * Resolves a hostname to an IP address.
     *
     * Literal IP hosts are validated directly without a DNS lookup. In strict mode,
     * non-public addresses are rejected.
     *
     * @param string $host Hostname or IP address to resolve
     * @param bool $strict TRUE to reject non-public addresses
     * @return string|null First resolved IP address, or null if none was found
     */
    protected function resolve( string $host, bool $strict ) : ?string
    {
        if( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return $this->allowedIp( $host, $strict ) ? $host : null;
        }

        foreach( @dns_get_record( $host, DNS_A + DNS_AAAA ) ?: [] as $record )
        {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if( $ip && $this->allowedIp( $ip, $strict ) ) {
                return $ip;
            }
        }

        foreach( @gethostbynamel( $host ) ?: [] as $ip )
        {
            if( $this->allowedIp( $ip, $strict ) ) {
                return $ip;
            }
        }

        return null;
    }


    /**
     * Checks whether an IP address is valid for the requested mode.
     *
     * @param string $ip IPv4 or IPv6 address to validate
     * @param bool $strict TRUE to reject non-public addresses
     * @return bool TRUE if the address is valid and allowed in the requested mode
     */
    protected function allowedIp( string $ip, bool $strict ) : bool
    {
        if( !$strict ) {
            return (bool) filter_var( $ip, FILTER_VALIDATE_IP );
        }

        // NAT64 addresses (64:ff9b::/96) are global but embed the IPv4 address the gateway connects to
        if( ( $bin = @inet_pton( $ip ) ) !== false && strlen( $bin ) === 16
            && str_starts_with( $bin, "\x00\x64\xff\x9b" . str_repeat( "\x00", 8 ) )
        ) {
            $ip = (string) inet_ntop( substr( $bin, 12 ) );
        }

        // Rejects carrier-grade NAT like the 100.100.100.200 metadata service of Alibaba Cloud too
        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
    }
}
