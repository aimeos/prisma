<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class MetaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'meta', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'A coastal sunset']]],
                'usage' => ['total_tokens' => 10],
            ] )
            ->ensure( 'describe' )
            ->describe( Video::fromBinary( 'MP4', 'video/mp4' ), 'fr', ['temperature' => 0.2, 'unknown' => 1] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'https://api.meta.ai/v1/chat/completions', (string) $request->getUri() );
            $this->assertSame( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );
            $this->assertSame( 'muse-spark-1.3', $body['model'] );
            $this->assertSame( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'unknown', $body );
            $this->assertSame( 'video_url', $content[0]['type'] );
            $this->assertSame( 'data:video/mp4;base64,' . base64_encode( 'MP4' ), $content[0]['video_url']['url'] );
            $this->assertStringContainsString( '"fr"', $content[1]['text'] );
        } );

        $this->assertSame( 'A coastal sunset', $response->text() );
        $this->assertSame( 10.0, $response->usage()['used'] );
    }


    public function testDescribeUrl() : void
    {
        $this->prisma( 'video', 'meta', ['api_key' => 'test'] )
            ->response( ['choices' => [['message' => ['content' => 'A clip']]]] )
            ->ensure( 'describe' )
            ->describe( Video::fromUrl( 'https://example.com/clip.mp4' ) );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $this->assertSame( 'https://example.com/clip.mp4', $body['messages'][0]['content'][0]['video_url']['url'] );
        } );
    }
}
