<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class OpenrouterTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'openrouter', ['api_key' => 'test'] )
            ->response( [
                'choices' => [['message' => ['content' => 'A coastal sunset']]],
                'usage' => ['total_tokens' => 10],
            ] )
            ->ensure( 'describe' )
            ->describe( Video::fromBinary( 'MP4', 'video/mp4' ), 'fr' );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'https://openrouter.ai/api/v1/chat/completions', (string) $request->getUri() );
            $this->assertSame( 'google/gemini-3.7-flash', $body['model'] );
            $this->assertSame( 'video_url', $content[0]['type'] );
            $this->assertSame( 'data:video/mp4;base64,' . base64_encode( 'MP4' ), $content[0]['video_url']['url'] );
            $this->assertStringContainsString( '"fr"', $content[1]['text'] );
        } );

        $this->assertSame( 'A coastal sunset', $response->text() );
    }


    public function testImagineWithFrames() : void
    {
        $this->prisma( 'video', 'openrouter', ['api_key' => 'test'] )
            ->response( ['id' => 'job-1', 'status' => 'pending'], [], 202 );
        $this->response( [
            'id' => 'job-1',
            'status' => 'completed',
            'unsigned_urls' => ['https://example.com/one.mp4', 'https://example.com/two.mp4'],
            'usage' => ['cost' => 0.5],
        ] );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'A forest path', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [Image::fromUrl( 'https://example.com/ignored.png', 'image/png' )],
        ], [
            'duration' => 8,
            'resolution' => '720p',
            'aspectRatio' => '16:9',
            'generate_audio' => false,
            'provider' => ['only' => ['google-vertex']],
            'unknown' => true,
        ] );

        $this->assertSame( ['https://example.com/one.mp4', 'https://example.com/two.mp4'], array_map(
            fn( $file ) => $file->url(),
            $response->files()
        ) );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://openrouter.ai/api/v1/videos', (string) $request->getUri() );
        $this->assertSame( 'google/veo-3.1', $body['model'] );
        $this->assertSame( ['first_frame', 'last_frame'], array_column( $body['frame_images'], 'frame_type' ) );
        $this->assertArrayNotHasKey( 'input_references', $body );
        $this->assertSame( 8, $body['duration'] );
        $this->assertSame( '16:9', $body['aspect_ratio'] );
        $this->assertFalse( $body['generate_audio'] );
        $this->assertSame( ['google-vertex'], $body['provider']['only'] );
        $this->assertArrayNotHasKey( 'unknown', $body );
        $this->assertSame( 0.5, $response->usage()['used'] );
        $this->assertSame( 'job-1', $response->meta()['id'] );
        $this->assertSame( 'https://openrouter.ai/api/v1/videos/job-1', (string) $this->requests()[1]->getUri() );
    }


    public function testImagineWithReferences() : void
    {
        $this->prisma( 'video', 'openrouter', ['api_key' => 'test'] )
            ->response( ['id' => 'job-2'], [], 202 );
        $this->response( [
            'status' => 'completed',
            'unsigned_urls' => ['https://example.com/video.mp4'],
        ] );

        $response = $this->provider()->imagine( 'Reference these', [
            'references' => [
                Image::fromUrl( 'https://example.com/image.png', 'image/png' ),
                Audio::fromUrl( 'https://example.com/audio.mp3', 'audio/mpeg' ),
                Video::fromUrl( 'https://example.com/source.mp4', 'video/mp4' ),
            ],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( ['image_url', 'audio_url', 'video_url'], array_column( $body['input_references'], 'type' ) );
    }


    #[DataProvider( 'terminalStatusProvider' )]
    public function testTerminalStatusFails( string $status ) : void
    {
        $this->prisma( 'video', 'openrouter', ['api_key' => 'test'] )
            ->response( ['id' => 'job-3'], [], 202 );
        $this->response( ['status' => $status, 'error' => 'Job ' . $status] );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'Job ' . $status );

        $this->provider()->imagine( 'prompt' )->first();
    }


    /** @return array<string, array{string}> */
    public static function terminalStatusProvider() : array
    {
        return [
            'failed' => ['failed'],
            'cancelled' => ['cancelled'],
            'expired' => ['expired'],
            'unknown' => ['unknown'],
        ];
    }
}
