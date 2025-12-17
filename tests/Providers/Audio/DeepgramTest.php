<?php

namespace Tests\Providers\Audio;

use Aimeos\Prisma\Files\Audio;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class DeepgramTest extends TestCase
{
    use MakesPrismaRequests;


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'deepgram', ['api_key' => 'test'] )
            ->response( 'MP3' )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', ['test'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.deepgram.com/v1/speak?model=aura-asteria-en', (string) $request->getUri() );
        } );

        $this->assertEquals( 'MP3', $response->binary() );
    }
}
