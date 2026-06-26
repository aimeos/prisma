<?php

namespace Tests\Testing;

use Aimeos\Prisma\Testing\Sse;
use PHPUnit\Framework\TestCase;


class SseTest extends TestCase
{
    public function testFromArrayDataIsJsonEncoded() : void
    {
        $sse = Sse::from( [
            ['data' => ['type' => 'delta', 'text' => 'hi']],
        ] );

        $this->assertEquals( "data: {\"type\":\"delta\",\"text\":\"hi\"}\n\n", $sse );
    }


    public function testFromBareArrayIsTreatedAsData() : void
    {
        $sse = Sse::from( [
            ['type' => 'x'],
        ] );

        $this->assertEquals( "data: {\"type\":\"x\"}\n\n", $sse );
    }


    public function testFromEventNameEmitsEventLine() : void
    {
        $sse = Sse::from( [
            ['event' => 'message_start', 'data' => ['x' => 1]],
        ] );

        $this->assertEquals( "event: message_start\ndata: {\"x\":1}\n\n", $sse );
    }


    public function testFromMultipleEvents() : void
    {
        $sse = Sse::from( [
            ['event' => 'a', 'data' => ['n' => 1]],
            ['data' => '[DONE]'],
        ] );

        $this->assertEquals( "event: a\ndata: {\"n\":1}\n\ndata: [DONE]\n\n", $sse );
    }


    public function testFromStringDataIsVerbatim() : void
    {
        $sse = Sse::from( [
            ['data' => '[DONE]'],
        ] );

        $this->assertEquals( "data: [DONE]\n\n", $sse );
    }
}
