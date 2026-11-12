<?php

namespace Tests\Concerns;

use Aimeos\Prisma\Concerns\HasHttpResponse;
use Aimeos\Prisma\Concerns\HasHttpStream;
use Aimeos\Prisma\Exceptions\PrismaException;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;


class HasHttpStreamTest extends TestCase
{
    public function testStreamsEvents() : void
    {
        $events = iterator_to_array( $this->object()->events( "data: {\"a\":1}\n\ndata: {\"b\":2}\n\n" ), false );

        $this->assertSame( [['a' => 1], ['b' => 2]], $events );
    }


    public function testLimitsStreamDuration() : void
    {
        $object = $this->object();
        ( new \ReflectionProperty( $object, 'maxStreamDuration' ) )->setValue( $object, -1 );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'maximum duration' );

        iterator_to_array( $object->events( "data: {\"a\":1}\n\n" ) );
    }


    private function object() : object
    {
        return new class {
            use HasHttpResponse, HasHttpStream;

            /**
             * @return \Generator<int, array<string, mixed>>
             */
            public function events( string $content ) : \Generator
            {
                return $this->streamData( Utils::streamFor( $content ) );
            }
        };
    }
}
