<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\FailedException;
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
            $this->assertEquals( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent', (string) $request->getUri() );
        } );

        $this->assertEquals( 'a video description', $response->text() );
    }


    public function testDescribeUpload() : void
    {
        $this->prisma( 'video', 'gemini', ['api_key' => 'test'] )
            ->response( '', ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=1'] );
        $this->response( '' );
        $this->response( ['file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'mimeType' => 'video/mp4', 'state' => 'PROCESSING']] );
        $this->response( ['name' => 'files/abc', 'state' => 'ACTIVE'] );
        $this->response( ['candidates' => [['content' => ['parts' => [['text' => 'a long video']]]]]] );

        // videos larger than 14 MiB are uploaded in parts of 32 MiB
        $video = Video::fromBinary( str_repeat( 'v', 33 * 1024 * 1024 ), 'video/mp4' )->as( 'v.mp4' );
        $response = $this->provider()->describe( $video, 'en' );

        $this->assertSame( 'a long video', $response->text() );

        [$start, $part1, $part2, $state, $generate] = $this->requests();

        $this->assertSame( 'https://generativelanguage.googleapis.com/upload/v1beta/files', (string) $start->getUri() );
        $this->assertSame( 'start', $start->getHeaderLine( 'X-Goog-Upload-Command' ) );
        $this->assertSame( (string) ( 33 * 1024 * 1024 ), $start->getHeaderLine( 'X-Goog-Upload-Header-Content-Length' ) );
        $this->assertSame( 'video/mp4', $start->getHeaderLine( 'X-Goog-Upload-Header-Content-Type' ) );
        $this->assertSame( ['file' => ['display_name' => 'v.mp4']], json_decode( (string) $start->getBody(), true ) );

        $this->assertSame( 'upload', $part1->getHeaderLine( 'X-Goog-Upload-Command' ) );
        $this->assertFalse( $part1->hasHeader( 'x-goog-api-key' ) );
        $this->assertSame( '0', $part1->getHeaderLine( 'X-Goog-Upload-Offset' ) );
        $this->assertSame( 32 * 1024 * 1024, $part1->getBody()->getSize() );
        $this->assertSame( 'upload, finalize', $part2->getHeaderLine( 'X-Goog-Upload-Command' ) );
        $this->assertFalse( $part2->hasHeader( 'x-goog-api-key' ) );
        $this->assertSame( (string) ( 32 * 1024 * 1024 ), $part2->getHeaderLine( 'X-Goog-Upload-Offset' ) );
        $this->assertSame( 1024 * 1024, $part2->getBody()->getSize() );

        $this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/files/abc', (string) $state->getUri() );

        $body = json_decode( (string) $generate->getBody(), true );
        $this->assertSame( ['fileUri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'mimeType' => 'video/mp4'], $body['contents'][0]['parts'][0]['fileData'] );
    }


    public function testDescribeUploadFailed() : void
    {
        $this->prisma( 'video', 'gemini', ['api_key' => 'test'] )
            ->response( '', ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=1'] );
        $this->response( ['file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'PROCESSING']] );
        $this->response( ['name' => 'files/abc', 'state' => 'FAILED'] );

        $response = $this->provider()->describe( Video::fromBinary( str_repeat( 'v', 15 * 1024 * 1024 ), 'video/mp4' ) );

        $this->expectException( FailedException::class );
        $response->text();
    }


    public function testDescribeUploadOnce() : void
    {
        $this->prisma( 'video', 'gemini', ['api_key' => 'test'] )
            ->response( '', ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=1'] );
        $this->response( ['file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'ACTIVE']] );
        $this->response( ['candidates' => [['content' => ['parts' => [['text' => 'first']]]]]] );
        $this->response( ['candidates' => [['content' => ['parts' => [['text' => 'second']]]]]] );

        $video = Video::fromBinary( str_repeat( 'v', 15 * 1024 * 1024 ), 'video/mp4' );

        $this->assertSame( 'first', $this->provider()->describe( $video )->text() );
        $this->assertSame( 'second', $this->provider()->describe( $video, 'de' )->text() );
        $this->assertCount( 4, $this->requests() );
    }


    public function testDescribeUploadUrlOnce() : void
    {
        $this->prisma( 'video', 'gemini', ['api_key' => 'test'] )
            ->response( '', ['X-Goog-Upload-URL' => 'https://generativelanguage.googleapis.com/upload/v1beta/files?upload_id=1'] );
        $this->response( ['file' => ['name' => 'files/abc', 'uri' => 'https://generativelanguage.googleapis.com/v1beta/files/abc', 'state' => 'ACTIVE']] );
        $this->response( ['candidates' => [['content' => ['parts' => [['text' => 'first']]]]]] );
        $this->response( ['candidates' => [['content' => ['parts' => [['text' => 'second']]]]]] );

        $video = DownloadedVideo::fromUrl( 'https://example.com/v.mp4', 'video/mp4' )->as( 'v.mp4' );

        $this->assertSame( 'first', $this->provider()->describe( $video )->text() );
        $this->assertSame( 'second', $this->provider()->describe( $video, 'de' )->text() );
        $this->assertSame( 1, DownloadedVideo::$downloads );

        $requests = $this->requests();

        $this->assertCount( 4, $requests );
        $this->assertSame( ['file' => ['display_name' => 'v.mp4']], json_decode( (string) $requests[0]->getBody(), true ) );
    }
}


/**
 * Video whose URL is "downloaded" from memory and counts the downloads.
 */
class DownloadedVideo extends Video
{
    public static int $downloads = 0;


    public function stream() : mixed
    {
        if( $this->stream !== null ) {
            return parent::stream();
        }

        self::$downloads++;

        $stream = fopen( 'php://temp', 'w+' );
        fwrite( $stream, str_repeat( 'v', 15 * 1024 * 1024 ) );
        rewind( $stream );

        return $stream;
    }
}
