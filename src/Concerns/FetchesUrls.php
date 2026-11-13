<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\ForbiddenException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Http\Network;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
 * Guzzle's handlers only send http(s) requests (so wrapper schemes like file:// or php://
 * cannot be fetched), redirects are bounded, TLS is verified, stalled transfers and those
 * taking longer than 10 minutes are aborted and the downloaded size is capped.
 * Requests are pinned to the public addresses of their hosts and rejected if they are sent
 * through a proxy unless internal hosts are allowed.
 */
trait FetchesUrls
{
    private Network $fetchNetwork;

    private const FETCH_STALL = 30;
    private const FETCH_TIMEOUT = 600;
    private const FETCH_REDIRECTS = 2;


    /**
     * Sets the Guzzle handler stack used for URL fetches.
     *
     * @param HandlerStack $stack Guzzle handler stack
     * @return static File instance
     */
    public function withClientHandler( HandlerStack $stack ) : static
    {
        $this->fetchNetwork = $this->network()->withHandler( $stack );
        return $this;
    }


    /**
     * Allows downloads from hosts resolving to internal IP addresses and through proxies.
     *
     * Only do that for URLs untrusted users can't choose, e.g. files on the own network.
     *
     * @param bool $allow TRUE to allow internal hosts and proxies, FALSE to allow public hosts only
     * @return static File instance
     */
    public function withInternalHosts( bool $allow = true ) : static
    {
        $this->fetchNetwork = $this->network()->withInternalHosts( $allow );
        return $this;
    }


    /**
     * Uses the given network access for URL fetches.
     *
     * File responses of providers pass the network of the provider without the middleware of its
     * handler stack, so result files are fetched with the handler and host restrictions of the
     * provider. The DNS cache is shared too, so each host is looked up once for the provider and
     * all of its files.
     *
     * @param Network $network Network access, e.g. of a provider
     * @return static File instance
     */
    public function withNetwork( Network $network ) : static
    {
        $this->fetchNetwork = $network;
        return $this;
    }


    /**
     * Returns the Guzzle client used for URL fetches.
     *
     * @return Client Guzzle client
     */
    protected function fetchClient() : Client
    {
        return new Client( ['handler' => $this->network()->handler()] );
    }


    /**
     * Returns the network access used for URL fetches.
     *
     * @return Network Network access
     */
    protected function network() : Network
    {
        return $this->fetchNetwork ??= new Network();
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
     * @return resource Readable stream positioned at the start of the content
     * @throws PrismaException If the URL is invalid, unreachable, empty or exceeds the limit
     */
    protected function fetchStream( string $url, int $limit ) : mixed
    {
        $body = $this->fetchBody( $url, $limit );

        if( !$body->getSize() || !( $stream = $body->detach() ) ) {
            throw new PrismaException( "Unable to fetch URL from {$url} or it is empty" );
        }

        rewind( $stream );
        return $stream;
    }


    /**
     * Returns safe Guzzle options for the given URL.
     * Redirects are disabled because Guzzle would write the bodies of redirect responses into
     * the same sink, so fetchBody() follows them itself. Stalled or overlong transfers are aborted.
     *
     * @param string $url The http(s) URL that will be fetched
     * @return array<string, mixed> Safe Guzzle request options
     * @throws PrismaException If the URL is too long or contains control characters or spaces
     */
    protected function safeHttp( string $url ) : array
    {
        if( strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
            throw new PrismaException( sprintf( 'Invalid or unsafe URL: %s', $url ) );
        }

        return [
            'verify' => true,
            'connect_timeout' => 10,
            // bounds slowly trickling transfers which would never stall
            'timeout' => self::FETCH_TIMEOUT,
            'allow_redirects' => false,
            'curl' => [
                // curl has no read timeout, abort transfers slower than 1 KiB/s instead
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => self::FETCH_STALL,
            ],
        ];
    }


    /**
     * Downloads a URL into a temporary stream and follows a limited number of redirects.
     *
     * @param string $url HTTP(S) URL to fetch
     * @param int $limit Positive maximum to enforce, or negative sample size to return without an error
     * @return StreamInterface Downloaded content
     * @throws ForbiddenException If the server denies access, e.g. for an expired signed URL
     * @throws NotFoundException If the file doesn't exist (anymore)
     * @throws PrismaException If the URL or redirect is invalid, the request fails or the file exceeds the limit
     */
    protected function fetchBody( string $url, int $limit ) : StreamInterface
    {
        $client = $this->fetchClient();

        for( $redirects = 0; ; $redirects++ )
        {
            $body = Utils::streamFor();
            $response = $this->fetchRequest( $client, $url, $limit, $body );

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
     * Samples are requested as range, so servers supporting ranges complete the transfer and
     * the connection can be reused instead of being closed by the abort.
     *
     * @param Client $client Guzzle client
     * @param string $url HTTP(S) URL to request
     * @param int $limit Positive maximum to enforce, or negative sample size to return without an error
     * @param StreamInterface $body Stream the response body is written to
     * @return ResponseInterface Response containing the status and headers
     * @throws ForbiddenException If the server denies access, e.g. for an expired signed URL
     * @throws NotFoundException If the file doesn't exist (anymore)
     * @throws PrismaException If the URL is unsafe, the request fails or the file exceeds the limit
     */
    private function fetchRequest( Client $client, string $url, int $limit, StreamInterface $body ) : ResponseInterface
    {
        $head = null;
        $maximum = $limit < 0 ? -$limit : min( $limit, PHP_INT_MAX - 1 ) + 1;

        $options = $this->safeHttp( $url ) + [
            'on_headers' => function( ResponseInterface $response ) use ( &$head, $url, $limit ) : void {
                $this->fetchHeaders( $head = $response, $url, $limit );
            },
            'sink' => new DroppingStream( $body, $maximum ),
        ];

        if( $limit < 0 ) {
            $options['headers']['Range'] = 'bytes=0-' . ( $maximum - 1 );
        }

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
