<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\ForbiddenException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\DroppingStream;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;


/**
 * Fetching of remote URLs.
 *
 * Requests are restricted to http(s) (so wrapper schemes like file:// or php:// cannot be
 * fetched), redirects are bounded and kept on http(s), TLS is verified, stalled transfers
 * and those taking longer than 10 minutes are aborted and the downloaded size is capped.
 * DNS is resolved and pinned for every request; strict requests additionally reject
 * non-public addresses.
 */
trait FetchesUrls
{
    private ?HandlerStack $fetchHandler = null;

    /** @var array<int, string>|null */
    private ?array $fetchHosts = null;

    private const FETCH_STALL = 30;
    private const FETCH_TIMEOUT = 600;
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
        return (string) $this->fetchBody( $url, $limit, $strict );
    }


    /**
     * Returns the Guzzle client used for URL fetches.
     *
     * @return Client Guzzle client
     */
    protected function fetchClient() : Client
    {
        // The DNS pin and the low speed limit are curl options, which StreamHandler would ignore
        $handler = $this->fetchHandler ?? HandlerStack::create( new CurlHandler );

        return new Client( ['handler' => $handler] );
    }


    /**
     * Downloads an http(s) URL into a temporary stream.
     *
     * Up to 2 MiB are kept in memory, larger downloads are buffered in a temporary file, so
     * big files don't exhaust the memory. The size limit is enforced while downloading and
     * before the stream is returned, so no partial content is ever handed to the caller.
     *
     * @param string $url Remote URL to fetch
     * @param int $limit Maximum number of bytes
     * @param bool $strict TRUE to resolve and reject private IPs, FALSE to allow internal IPs too
     * @return resource Readable stream positioned at the start of the content
     * @throws PrismaException If the URL is invalid, unreachable, empty or exceeds the limit
     */
    protected function fetchStream( string $url, int $limit, bool $strict ) : mixed
    {
        $body = $this->fetchBody( $url, $limit, $strict );

        if( !$body->getSize() || !( $stream = $body->detach() ) ) {
            throw new PrismaException( "Unable to fetch URL from {$url} or it is empty" );
        }

        rewind( $stream );
        return $stream;
    }


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
        // Rejects carrier-grade NAT like the 100.100.100.200 metadata service of Alibaba Cloud too
        $flags = $strict ? FILTER_FLAG_GLOBAL_RANGE : 0;

        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, $flags );
    }


    /**
     * Returns Guzzle options that resolve and pin the connection for the given URL.
     * Redirects are disabled so fetch() can validate and pin every target separately
     * and stalled or overlong transfers are aborted.
     *
     * @param string $url The http(s) URL that will be fetched
     * @param bool $strict TRUE to reject non-public addresses
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
            // bounds slowly trickling transfers which would never stall
            'timeout' => self::FETCH_TIMEOUT,
            'allow_redirects' => false,
            'curl' => [
                CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
                // curl has no read timeout, abort transfers slower than 1 KiB/s instead
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => self::FETCH_STALL,
            ],
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


    /**
     * Downloads a URL into a temporary stream and validates every redirect before following it.
     *
     * @param string $url HTTP(S) URL to fetch
     * @param int $limit Positive maximum to enforce, or negative sample size to return without an error
     * @param bool $strict TRUE to reject non-public IP addresses
     * @return StreamInterface Downloaded content
     * @throws ForbiddenException If the server denies access, e.g. for an expired signed URL
     * @throws NotFoundException If the file doesn't exist (anymore)
     * @throws PrismaException If the URL or redirect is unsafe, the request fails or the file exceeds the limit
     */
    private function fetchBody( string $url, int $limit, bool $strict ) : StreamInterface
    {
        $client = $this->fetchClient();

        for( $redirects = 0; ; $redirects++ )
        {
            $body = Utils::streamFor();
            $response = $this->fetchRequest( $client, $url, $limit, $strict, $body );

            if( !in_array( $response->getStatusCode(), [301, 302, 303, 307, 308], true ) )
            {
                $this->fetchSize( $url, $limit, (int) $body->getSize() );
                return $body;
            }

            $location = trim( $response->getHeaderLine( 'Location' ) );

            if( $location === '' || $redirects >= self::FETCH_REDIRECTS ) {
                throw new PrismaException( sprintf( 'Too many or invalid redirects for URL: %s', $url ) );
            }

            $url = (string) UriResolver::resolve( new Uri( $url ), new Uri( $location ) );
        }
    }


    /**
     * Returns the exception for a failed request.
     *
     * Denied and missing files, e.g. expired result URLs of providers, get typed exceptions
     * because retrying won't make them available again.
     *
     * @param string $url Requested URL
     * @param int $status HTTP status code, or 0 if no response was received
     * @param string $reason Reason of the failure
     * @return PrismaException Exception to throw
     */
    private function fetchError( string $url, int $status, string $reason ) : PrismaException
    {
        $message = sprintf( 'Unable to fetch URL from %s: %s', $url, $reason );

        return match( $status ) {
            403 => new ForbiddenException( $message ),
            404, 410 => new NotFoundException( $message ),
            default => new PrismaException( $message ),
        };
    }


    /**
     * Rejects error responses and files larger than the limit before their body is transferred.
     *
     * @param ResponseInterface $response Response containing the status and headers
     * @param string $url Requested URL
     * @param int $limit Positive maximum to enforce, or negative sample size
     * @throws ForbiddenException If the server denies access, e.g. for an expired signed URL
     * @throws NotFoundException If the file doesn't exist (anymore)
     * @throws PrismaException If the request failed or the file exceeds the limit
     */
    private function fetchHeaders( ResponseInterface $response, string $url, int $limit ) : void
    {
        if( ( $status = $response->getStatusCode() ) >= 400 ) {
            throw $this->fetchError( $url, $status, $status . ' ' . $response->getReasonPhrase() );
        }

        $this->fetchSize( $url, $limit, (int) $response->getHeaderLine( 'Content-Length' ) );
    }


    /**
     * Sends a request and writes the response body into the given stream.
     *
     * The sink stops accepting data after one byte more than the limit or at the sample size,
     * which makes curl abort the transfer, so large files are never downloaded completely.
     *
     * @param Client $client Guzzle client
     * @param string $url HTTP(S) URL to request
     * @param int $limit Positive maximum to enforce, or negative sample size to return without an error
     * @param bool $strict TRUE to reject non-public IP addresses
     * @param StreamInterface $body Stream the response body is written to
     * @return ResponseInterface Response containing the status and headers
     * @throws ForbiddenException If the server denies access, e.g. for an expired signed URL
     * @throws NotFoundException If the file doesn't exist (anymore)
     * @throws PrismaException If the URL is unsafe, the request fails or the file exceeds the limit
     */
    private function fetchRequest( Client $client, string $url, int $limit, bool $strict, StreamInterface $body ) : ResponseInterface
    {
        $head = null;
        $maximum = $limit < 0 ? -$limit : min( $limit, PHP_INT_MAX - 1 ) + 1;

        $options = $this->safeHttp( $url, $strict ) + [
            'on_headers' => function( ResponseInterface $response ) use ( &$head, $url, $limit ) : void {
                $this->fetchHeaders( $head = $response, $url, $limit );
            },
            'sink' => new DroppingStream( $body, $maximum ),
        ];

        try
        {
            return $client->request( 'GET', $url, $options );
        }
        catch( GuzzleException $e )
        {
            if( $e->getPrevious() instanceof PrismaException ) {
                throw $e->getPrevious();
            }

            // curl reports an error if the sink stopped accepting data at the maximum size
            if( !$head instanceof ResponseInterface || $body->getSize() < $maximum ) {
                throw $this->fetchError( $url, 0, $e->getMessage() );
            }

            return $head;
        }
    }


    /**
     * Rejects files that are larger than the limit.
     *
     * @param string $url Requested URL
     * @param int $limit Positive maximum to enforce, or negative sample size
     * @param int $size Size of the file in bytes
     * @throws PrismaException If the file exceeds the limit
     */
    private function fetchSize( string $url, int $limit, int $size ) : void
    {
        if( $limit >= 0 && $size > $limit ) {
            throw new PrismaException( sprintf( 'File from %s exceeds the maximum size of %d bytes', $url, $limit ) );
        }
    }
}
