<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;


/**
 * Fetching of remote URLs.
 *
 * Requests are restricted to http(s) (so wrapper schemes like file:// or php:// cannot be
 * fetched), redirects are bounded and kept on http(s), TLS is verified, and the read time
 * and downloaded size are capped. Private and reserved addresses are intentionally allowed.
 */
trait FetchesUrls
{
    private ?HandlerStack $fetchHandler = null;

    private const FETCH_TIMEOUT = 30;
    private const FETCH_REDIRECTS = 2;


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
     * @param int $limit Maximum number of bytes to read
     * @param bool $strict TRUE to fail if the content exceeds the limit, FALSE to read at most the limit
     * @return string Fetched content
     * @throws PrismaException If the URL is invalid, unreachable or exceeds the limit
     */
    protected function fetch( string $url, int $limit, bool $strict ) : string
    {
        if( !$this->validUrl( $url ) ) {
            throw new PrismaException( sprintf( 'Invalid or unsafe URL: %s', $url ) );
        }

        try {
            $response = $this->fetchClient()->request( 'GET', $url, [
                'stream' => true,
                'verify' => true,
                'http_errors' => true,
                'connect_timeout' => 10,
                'read_timeout' => self::FETCH_TIMEOUT,
                'allow_redirects' => ['max' => self::FETCH_REDIRECTS, 'strict' => true, 'protocols' => ['http', 'https']],
            ] );
        } catch( GuzzleException $e ) {
            throw new PrismaException( sprintf( 'Unable to fetch URL from %s: %s', $url, $e->getMessage() ) );
        }

        $body = $response->getBody();
        $content = '';

        while( !$body->eof() && strlen( $content ) <= $limit ) {
            $content .= $body->read( max( 1, min( 65536, $limit + 1 - strlen( $content ) ) ) );
        }

        $body->close();

        if( $strict && strlen( $content ) > $limit ) {
            throw new PrismaException( sprintf( 'File from %s exceeds the maximum size of %d bytes', $url, $limit ) );
        }

        return $strict ? $content : substr( $content, 0, $limit );
    }


    /**
     * Returns the Guzzle client used for URL fetches.
     *
     * @return Client Guzzle client
     */
    protected function fetchClient() : Client
    {
        return new Client( $this->fetchHandler ? ['handler' => $this->fetchHandler] : [] );
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

        return !empty( $parsed['host'] ) && (bool) filter_var( $parsed['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME );
    }
}
