<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ByteplusTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( [
                'id' => 'resp-1',
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => 'a video description']],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
            ] )
            ->ensure( 'describe' )
            ->describe( Video::fromUrl( 'https://example.com/video.mp4', 'video/mp4' ), 'fr', [
                'fps' => 0.5,
                'temperature' => 0.2,
                'duration' => 5,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['input'][0]['content'];

            $this->assertSame( 'https://ark.ap-southeast.bytepluses.com/api/v3/responses', (string) $request->getUri() );
            $this->assertSame( 'seed-2-0-lite-260228', $body['model'] );
            $this->assertSame( 'https://example.com/video.mp4', $content[0]['video_url'] );
            $this->assertSame( 0.5, $content[0]['fps'] );
            $this->assertStringContainsString( '"fr"', $content[1]['text'] );
            $this->assertSame( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'duration', $body );
        } );

        $this->assertSame( 'a video description', $response->text() );
        $this->assertSame( 13, $response->usage()->totalTokens() );
    }


    public function testExtend() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'], [], 201 );
        $this->response( [
            'status' => 'succeeded',
            'content' => ['video_url' => 'https://example.com/extended.mp4'],
        ] );

        $response = $this->provider()
            ->ensure( 'extend' )
            ->extend( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Show the museum entrance', [
                'direction' => 'backward',
                'duration' => 8,
                'audio' => true,
                'unsupported' => true,
            ] );

        $this->assertSame( 'https://example.com/extended.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks', (string) $request->getUri() );
        $this->assertSame( 'dreamina-seedance-2-0-260128', $body['model'] );
        $this->assertSame( 'Extend [Video 1] backward. Show the museum entrance', $body['content'][0]['text'] );
        $this->assertSame( 'reference_video', $body['content'][1]['role'] );
        $this->assertSame( 'https://example.com/input.mp4', $body['content'][1]['video_url']['url'] );
        $this->assertSame( 8, $body['duration'] );
        $this->assertTrue( $body['generate_audio'] );
        $this->assertArrayNotHasKey( 'direction', $body );
        $this->assertArrayNotHasKey( 'unsupported', $body );
    }


    public function testImagineSilentlyDropsReferencesWhenFramesAreUsed() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'], [], 201 );
        $this->response( [
            'status' => 'succeeded',
            'content' => ['video_url' => 'https://example.com/video.mp4'],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [
                Image::fromUrl( 'https://example.com/reference.png', 'image/png' ),
                Audio::fromUrl( 'https://example.com/reference.mp3', 'audio/mpeg' ),
                Video::fromUrl( 'https://example.com/reference.mp4', 'video/mp4' ),
            ],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertCount( 3, $body['content'] );
        $this->assertSame( ['first_frame', 'last_frame'], array_column( array_slice( $body['content'], 1 ), 'role' ) );
        $this->assertStringNotContainsString( 'reference.', (string) $this->requests()[0]->getBody() );
    }


    public function testAudioOnlyReferencesAreIgnored() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'] );

        $this->provider()->imagine( 'prompt', [
            'references' => [Audio::fromUrl( 'https://example.com/reference.mp3', 'audio/mpeg' )],
        ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertCount( 1, $body['content'] );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'video', 'byteplus', ['api_key' => 'test'] )
            ->response( ['id' => 'task-1'], [], 201 );
        $this->response( [
            'status' => 'succeeded',
            'content' => ['video_url' => 'https://example.com/repainted.mp4'],
        ] );

        $response = $this->provider()
            ->ensure( 'repaint' )
            ->repaint( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Add falling snow', [
                'references' => [
                    Image::fromUrl( 'https://example.com/coat.png', 'image/png' ),
                    Audio::fromUrl( 'https://example.com/voice.mp3', 'audio/mpeg' ),
                    Video::fromUrl( 'https://example.com/motion.mp4', 'video/mp4' ),
                ],
            ], [
                'duration' => 5,
                'audio' => true,
            ] );

        $this->assertSame( 'https://example.com/repainted.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://ark.ap-southeast.bytepluses.com/api/v3/contents/generations/tasks', (string) $request->getUri() );
        $this->assertSame( 'dreamina-seedance-2-0-260128', $body['model'] );
        $this->assertSame( 'Add falling snow', $body['content'][0]['text'] );
        $this->assertSame( 'reference_video', $body['content'][1]['role'] );
        $this->assertSame( 'https://example.com/input.mp4', $body['content'][1]['video_url']['url'] );
        $this->assertSame(
            ['reference_video', 'reference_image', 'reference_audio', 'reference_video'],
            array_column( array_slice( $body['content'], 1 ), 'role' )
        );
        $this->assertSame( 5, $body['duration'] );
        $this->assertTrue( $body['generate_audio'] );
    }
}
