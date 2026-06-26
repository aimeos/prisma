<?php

namespace Tests\Responses;

use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Values\Citation;
use PHPUnit\Framework\TestCase;


class TextResponseTest extends TestCase
{
    public function testFakeChainsSetters() : void
    {
        $response = TextResponse::fake( 'hi' )->withUsage( 8 )->withReason( TextResponse::STOP );

        $this->assertEquals( 8, $response->usage()['used'] );
        $this->assertEquals( 'stop', $response->reason() );
    }


    public function testFakeStructured() : void
    {
        $response = TextResponse::fake( '{"name":"John"}', ['name' => 'John'] );

        $this->assertEquals( '{"name":"John"}', $response->text() );
        $this->assertEquals( ['name' => 'John'], $response->structured() );
    }


    public function testFakeText() : void
    {
        $response = TextResponse::fake( 'hello' );

        $this->assertEquals( 'hello', $response->text() );
        $this->assertEquals( 'hello', $response->output() );
    }


    public function testFakeTexts() : void
    {
        $response = TextResponse::fake( ['a', 'b'] );

        $this->assertEquals( 'a', $response->text() );
        $this->assertEquals( ['a', 'b'], $response->texts() );
        $this->assertEquals( 'ab', $response->output() );
    }


    public function testJsonEncodeSerializesNestedCitations() : void
    {
        $response = TextResponse::fake( 'Paris' )
            ->withCitations( [new Citation( 'Geo', null, null, 'Paris is the capital' )] );

        $decoded = json_decode( (string) json_encode( $response ), true );

        $this->assertEquals( ['Paris'], $decoded['texts'] );
        $this->assertEquals( 'Geo', $decoded['citations'][0]['title'] );
        $this->assertEquals( 'Paris is the capital', $decoded['citations'][0]['source'] );
    }


    public function testJsonSerializeExposesFields() : void
    {
        $response = TextResponse::fake( 'hi', ['k' => 'v'] )->withUsage( 8 )->withReason( TextResponse::STOP );

        $data = $response->jsonSerialize();

        $this->assertEquals( ['hi'], $data['texts'] );
        $this->assertEquals( ['k' => 'v'], $data['structured'] );
        $this->assertEquals( 8, $data['usage']['used'] );
        $this->assertEquals( 'stop', $data['reason'] );
        $this->assertSame( [], $data['citations'] );
        $this->assertSame( [], $data['steps'] );
    }
}
