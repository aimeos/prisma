<?php

namespace Tests\Providers\Audio;

use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AlibabaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testSpeak() : void
    {
        $response = $this->prisma( 'audio', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "audio": {
                        "url": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3",
                        "expires_at": 1700000000
                    }
                },
                "usage": {
                    "input_tokens": 10,
                    "output_tokens": 100
                },
                "request_id": "req-tts-123"
            }' )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', 'Cherry' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'qwen3-tts-flash', $body['model'] );
            $this->assertEquals( 'This is a test.', $body['input']['text'] );
            $this->assertEquals( 'Cherry', $body['input']['voice'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3', $response->url() );
        $this->assertEquals( ['request_id' => 'req-tts-123'], $response->meta() );
    }


    public function testSpeakDefaultVoice() : void
    {
        $response = $this->prisma( 'audio', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "audio": {
                        "url": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3"
                    }
                },
                "usage": {
                    "characters": 15
                },
                "request_id": "req-tts-456"
            }' )
            ->ensure( 'speak' )
            ->speak( 'This is a test.' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Cherry', $body['input']['voice'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3', $response->url() );
    }


    public function testSpeakWithOptions() : void
    {
        $response = $this->prisma( 'audio', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "audio": {
                        "url": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3"
                    }
                },
                "usage": {
                    "input_tokens": 20,
                    "output_tokens": 200
                },
                "request_id": "req-tts-789"
            }' )
            ->model( 'qwen3-tts-instruct-flash' )
            ->ensure( 'speak' )
            ->speak( 'Hello world', 'Cherry', [
                'language_type' => 'English',
                'instructions' => 'Speak slowly and clearly',
                'optimize_instructions' => true,
                'unknown_option' => 'ignored'
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'qwen3-tts-instruct-flash', $body['model'] );
            $this->assertEquals( 'Hello world', $body['input']['text'] );
            $this->assertEquals( 'Cherry', $body['input']['voice'] );
            $this->assertEquals( 'English', $body['input']['language_type'] );
            $this->assertEquals( 'Speak slowly and clearly', $body['input']['instructions'] );
            $this->assertTrue( $body['input']['optimize_instructions'] );
            $this->assertArrayNotHasKey( 'unknown_option', $body['input'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.mp3', $response->url() );
    }
}
