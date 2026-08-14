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
}
