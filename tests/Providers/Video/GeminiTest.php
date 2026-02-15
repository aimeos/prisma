<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GeminiTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'gemini', ['api_key' => 'test'] )
            ->response( json_encode( [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'a video description'
                        ]]
                    ]
                ]]
            ] ) )
            ->ensure( 'describe' )
            ->describe( Video::fromBinary( 'MP4', 'video/mp4' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', (string) $request->getUri() );
        } );

        $this->assertEquals( 'a video description', $response->text() );
    }
}
