<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AlibabaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( [
                'id' => 'chatcmpl-1',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'a video description'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
            ] )
            ->ensure( 'describe' )
            ->describe( Video::fromBinary( 'MP4', 'video/mp4' ), 'de', [
                'fps' => 1,
                'max_pixels' => 655360,
                'temperature' => 0.2,
                'duration' => 5,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions', (string) $request->getUri() );
            $this->assertSame( '', $request->getHeaderLine( 'X-DashScope-Async' ) );
            $this->assertSame( 'qwen3.7-plus', $body['model'] );
            $this->assertSame( 'data:video/mp4;base64,' . base64_encode( 'MP4' ), $content[0]['video_url']['url'] );
            $this->assertSame( 1, $content[0]['video_url']['fps'] );
            $this->assertSame( 655360, $content[0]['max_pixels'] );
            $this->assertStringContainsString( '"de"', $content[1]['text'] );
            $this->assertSame( 0.2, $body['temperature'] );
            $this->assertArrayNotHasKey( 'duration', $body );
        } );

        $this->assertSame( 'a video description', $response->text() );
        $this->assertSame( 13, $response->usage()->totalTokens() );
    }


    public function testExtend() : void
    {
        $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( ['output' => ['task_id' => 'task-1']], [], 202 );
        $this->response( [
            'output' => [
                'task_status' => 'SUCCEEDED',
                'video_url' => 'https://example.com/extended.mp4',
            ],
        ] );

        $response = $this->provider()
            ->ensure( 'extend' )
            ->extend( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Reveal the city skyline', [
                'negative_prompt' => 'rain',
                'resolution' => '720P',
                'duration' => 10,
                'prompt_extend' => false,
                'watermark' => true,
                'seed' => 123,
                'aspectRatio' => '9:16',
            ] );

        $this->assertSame( 'https://example.com/extended.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/video-generation/video-synthesis', (string) $request->getUri() );
        $this->assertSame( 'enable', $request->getHeaderLine( 'X-DashScope-Async' ) );
        $this->assertSame( 'wan2.7-i2v', $body['model'] );
        $this->assertSame( 'Reveal the city skyline', $body['input']['prompt'] );
        $this->assertSame( 'rain', $body['input']['negative_prompt'] );
        $this->assertSame( [['type' => 'first_clip', 'url' => 'https://example.com/input.mp4']], $body['input']['media'] );
        $this->assertSame( 10, $body['parameters']['duration'] );
        $this->assertFalse( $body['parameters']['prompt_extend'] );
        $this->assertArrayNotHasKey( 'aspectRatio', $body['parameters'] );
    }


    public function testImagineSilentlyFiltersFrameModeReferences() : void
    {
        $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( ['output' => ['task_id' => 'task-1']], [], 202 );
        $this->response( [
            'output' => [
                'task_status' => 'SUCCEEDED',
                'video_url' => 'https://example.com/video.mp4',
            ],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromUrl( 'https://example.com/start.png', 'image/png' ),
            'end' => Image::fromUrl( 'https://example.com/end.png', 'image/png' ),
            'references' => [
                Image::fromUrl( 'https://example.com/reference.png', 'image/png' ),
                Video::fromUrl( 'https://example.com/reference.mp4', 'video/mp4' ),
                Audio::fromUrl( 'https://example.com/driving.mp3', 'audio/mpeg' ),
                Audio::fromUrl( 'https://example.com/discarded.mp3', 'audio/mpeg' ),
            ],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'enable', $request->getHeaderLine( 'X-DashScope-Async' ) );
        $this->assertSame( 'wan2.7-i2v', $body['model'] );
        $this->assertSame( ['first_frame', 'last_frame', 'driving_audio'], array_column( $body['input']['media'], 'type' ) );
        $this->assertStringNotContainsString( 'reference.', (string) $request->getBody() );
        $this->assertStringNotContainsString( 'discarded.mp3', (string) $request->getBody() );
    }


    public function testRepaint() : void
    {
        $this->prisma( 'video', 'alibaba', ['api_key' => 'test'] )
            ->response( ['output' => ['task_id' => 'task-1']], [], 202 );
        $this->response( [
            'output' => [
                'task_status' => 'SUCCEEDED',
                'video_url' => 'https://example.com/repainted.mp4',
            ],
        ] );

        $response = $this->provider()
            ->ensure( 'repaint' )
            ->repaint( Video::fromUrl( 'https://example.com/input.mp4', 'video/mp4' ), 'Add falling snow', [
                'negative_prompt' => 'rain',
                'resolution' => '720P',
                'aspectRatio' => '9:16',
                'audio_setting' => 'origin',
                'prompt_extend' => false,
                'watermark' => true,
                'seed' => 123,
                'unsupported' => true,
            ] );

        $this->assertSame( 'https://example.com/repainted.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );

        $this->assertSame( 'https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/video-generation/video-synthesis', (string) $request->getUri() );
        $this->assertSame( 'enable', $request->getHeaderLine( 'X-DashScope-Async' ) );
        $this->assertSame( 'wan2.7-videoedit', $body['model'] );
        $this->assertSame( 'Add falling snow', $body['input']['prompt'] );
        $this->assertSame( 'rain', $body['input']['negative_prompt'] );
        $this->assertSame( [['type' => 'video', 'url' => 'https://example.com/input.mp4']], $body['input']['media'] );
        $this->assertSame( '9:16', $body['parameters']['ratio'] );
        $this->assertSame( 'origin', $body['parameters']['audio_setting'] );
        $this->assertFalse( $body['parameters']['prompt_extend'] );
        $this->assertArrayNotHasKey( 'unsupported', $body['parameters'] );
    }
}
