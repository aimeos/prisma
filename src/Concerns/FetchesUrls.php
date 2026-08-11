<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;


/**
 * Fetching of remote URLs.
 *
 * Requests are restricted to http(s) (so wrapper schemes like file:// or php:// cannot be
 * fetched), redirects are bounded and kept on http(s), TLS is verified, and the read time
 * and downloaded size are capped. DNS is resolved and pinned for every request; strict
 * requests additionally reject private and reserved addresses.
 */
trait FetchesUrls
{
    private ?HandlerStack $fetchHandler = null;

    /** @var array<int, string>|null */
    private ?array $fetchHosts = null;

    private const FETCH_TIMEOUT = 30;
    private const FETCH_REDIRECTS = 2;


    /**
     * Restricts remote fetches to HTTPS URLs on the given hosts and their subdomains.
     *
     * @param array<int, string> $hosts Allowed host names
     * @return self File instance
     */
    public function restrictHosts( array $hosts ) : self
    {
        $this->fetchHosts = array_values( array_unique( array_filter( array_map(
            fn( string $host ) => strtolower( trim( $host, ". \t\n\r\0\x0B" ) ),
            $hosts
        ) ) ) );

        return $this;
    }


    /**
     * Sets the Guzzle handler stack used for URL fetches.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return self File instance
     */
    public function withClientHandler( HandlerStack $stack ) : self
    {
        $this->fetchHandler = $stack;
        return $this;
    }


    /**
     * Fetches bytes from an http(s) URL.
     *
     * @param string $url Remote URL to fetch
     * @param int $limit Positive maximum to enforce, or negative sample size to return without an error
     * @param bool $strict TRUE to resolve and reject private IPs, FALSE to allow internal IPs too
     * @return string Fetched content
     * @throws PrismaException If the URL is invalid, unreachable or exceeds the limit
     */
    protected function fetch( string $url, int $limit, bool $strict ) : string
    {
        $body = $this->fetchResponse( $url, $strict )->getBody();
        $length = abs( $limit );

        try
        {
            if( ( $size = $body->getSize() ) !== null )
            {
                if( $limit >= 0 && $size > $length ) {
                    throw new PrismaException( sprintf( 'File from %s exceeds the maximum size of %d bytes', $url, $length ) );
                }

                if( $size <= $length ) {
                    return $body->getContents();
                }
            }

            $read = 0;
            $content = '';
            $maximum = $length + ( $limit >= 0 ? 1 : 0 );

            while( !$body->eof() && $read < $maximum )
            {
                $content .= $chunk = $body->read( min( 65536, $maximum - $read ) );
                $read += strlen( $chunk );
            }

            if( $limit >= 0 && $read > $length ) {
                throw new PrismaException( sprintf( 'File from %s exceeds the maximum size of %d bytes', $url, $length ) );
            }

            return $content;
        }
        finally
        {
            $body->close();
        }
    }


    /**
     * Returns the Guzzle client used for URL fetches.
     *
     * @return Client Guzzle client
     */
    protected function fetchClient() : Client
    {
        // Guzzle's default stack can route streamed responses through StreamHandler, which
        // ignores CURLOPT_RESOLVE and would bypass the validated DNS pin.
        $handler = $this->fetchHandler ?? HandlerStack::create( new CurlHandler );

        return new Client( ['handler' => $handler] );
    }


    /**
     * Requests a URL and validates every redirect before following it.
     */
    protected function fetchResponse( string $url, bool $strict ) : ResponseInterface
    {
        if( !$this->validUrl( $url ) ) {
            throw new PrismaException( sprintf( 'Invalid or unsafe URL: %s', $url ) );
        }

        $options = [
            'stream' => true,
            'verify' => true,
            'http_errors' => true,
            'connect_timeout' => 10,
            'read_timeout' => self::FETCH_TIMEOUT,
            // A host-restricted provider URL must not escape its allowlist via redirects.
            'allow_redirects' => $this->fetchHosts === null
                ? ['max' => self::FETCH_REDIRECTS, 'strict' => true, 'protocols' => ['http', 'https']]
                : false,
        ];

        try
        {
            $client = $this->fetchClient();

            for( $redirects = 0; ; $redirects++ )
            {
                $response = $client->request( 'GET', $url, $this->safeHttp( $url, $strict ) + $options );

                if( !in_array( $response->getStatusCode(), [301, 302, 303, 307, 308], true ) ) {
                    return $response;
                }

                $location = trim( $response->getHeaderLine( 'Location' ) );
                $response->getBody()->close();

                if( $location === '' || $redirects >= self::FETCH_REDIRECTS ) {
                    throw new PrismaException( sprintf( 'Too many or invalid redirects for URL: %s', $url ) );
                }

                $url = (string) UriResolver::resolve( new Uri( $url ), new Uri( $location ) );
            }
        } catch( GuzzleException $e ) {
            throw new PrismaException( sprintf( 'Unable to fetch URL from %s: %s', $url, $e->getMessage() ) );
        }
    }


    /**
     * Resolves a hostname to an IP address.
     *
     * Literal IP hosts are validated directly without a DNS lookup. In strict mode,
     * private and reserved addresses are rejected.
     *
     * @param string $host Hostname or IP address to resolve
     * @param bool $strict TRUE to reject private and reserved addresses
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
     */
    protected function allowedIp( string $ip, bool $strict ) : bool
    {
        $flags = $strict ? FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE : 0;

        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags );
    }


    /**
     * Returns Guzzle options that resolve and pin the connection for the given URL.
     * Redirects are disabled so fetch() can validate and pin every target separately.
     *
     * @param string $url The http(s) URL that will be fetched
     * @param bool $strict TRUE to reject private and reserved addresses
     * @return array<string, mixed> Safe Guzzle request options
     * @throws PrismaException If the URL is invalid or the host does not resolve
     */
    protected function safeHttp( string $url, bool $strict ) : array
    {
        if( !$this->validUrl( $url ) ) {
            throw new PrismaException( sprintf( 'Invalid or unsafe URL: %s', $url ) );
        }

        $parsed = (array) parse_url( $url );
        $host = (string) ( $parsed['host'] ?? '' );
        $port = $parsed['port'] ?? ( ( $parsed['scheme'] ?? '' ) === 'https' ? 443 : 80 );

        if( !( $ip = $this->resolve( $host, $strict ) ) ) {
            throw new PrismaException( sprintf( 'Host "%s" does not resolve to an allowed address', $host ) );
        }

        return [
            'verify' => true,
            'connect_timeout' => 10,
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip]],
        ];
    }


    /**
     * Checks whether the URL is a syntactically valid http(s) URL.
     *
     * @param string $url URL to check
     * @return bool TRUE if the URL is acceptable, FALSE otherwise
     */
    protected function validUrl( string $url ) : bool
    {
        if( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $url ) || str_starts_with( $url, '//' ) ) {
            return false;
        }

        if( !is_array( $parsed = parse_url( $url ) ) ) {
            return false;
        }

        if( !empty( $parsed['path'] ) && str_contains( (string) $parsed['path'], '..' ) ) {
            return false;
        }

        if( empty( $parsed['scheme'] ) || !in_array( $parsed['scheme'], ['http', 'https'], true ) ) {
            return false;
        }

        if( empty( $parsed['host'] ) || !filter_var( $parsed['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
            return false;
        }

        if( $this->fetchHosts === null ) {
            return true;
        }

        if( $parsed['scheme'] !== 'https' ) {
            return false;
        }

        $host = strtolower( (string) $parsed['host'] );

        foreach( $this->fetchHosts as $allowed )
        {
            if( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
                return true;
            }
        }

        return false;
    }
}
