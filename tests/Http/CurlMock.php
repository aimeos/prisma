<?php

namespace Tests\Http;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;


/**
 * cURL handler which passes the requests to a mock handler instead of sending them.
 */
class CurlMock extends CurlHandler
{
    /** @var callable */
    private $handler;


    public function __construct( callable $handler )
    {
        $this->handler = $handler;
    }


    public function __invoke( RequestInterface $request, array $options ) : PromiseInterface
    {
        return ( $this->handler )( $request, $options );
    }
}
