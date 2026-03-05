<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AlibabaTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.png"
                            }]
                        }
                    }]
                },
                "usage": {
                    "image_count": 1,
                    "width": 1024,
                    "height": 1024
                },
                "request_id": "req-12345"
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon cat', [], ['size' => '1024*1024', 'negative_prompt' => 'blurry', 'seed' => 42] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'qwen-image-2.0-pro', $body['model'] );
            $this->assertEquals( 'a cartoon cat', $body['input']['messages'][0]['content'][0]['text'] );
            $this->assertEquals( '1024*1024', $body['parameters']['size'] );
            $this->assertEquals( 'blurry', $body['parameters']['negative_prompt'] );
            $this->assertEquals( 42, $body['parameters']['seed'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/test.png', $file->url() );
        $this->assertEquals( [
            'usage' => [
                'image_count' => 1,
                'width' => 1024,
                'height' => 1024
            ],
            'request_id' => 'req-12345'
        ], $file->meta() );
    }


    public function testImagineWithUrlImages() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png"
                            }]
                        }
                    }]
                },
                "usage": {"image_count": 1},
                "request_id": "req-img-url"
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'make it brighter', [Image::fromUrl( 'https://example.com/photo.jpg' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input']['messages'][0]['content'];
            $this->assertEquals( 'make it brighter', $content[0]['text'] );
            $this->assertEquals( 'https://example.com/photo.jpg', $content[1]['image'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png', $file->url() );
    }


    public function testImagineWithBase64Images() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png"
                            }]
                        }
                    }]
                },
                "usage": {"image_count": 1},
                "request_id": "req-img-b64"
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'add a hat', [Image::fromBase64( 'dGVzdA==', 'image/jpeg' )] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input']['messages'][0]['content'];
            $this->assertEquals( 'add a hat', $content[0]['text'] );
            $this->assertEquals( 'data:image/jpeg;base64,dGVzdA==', $content[1]['image'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png', $file->url() );
    }


    public function testImagineWithMultipleImages() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png"
                            }]
                        }
                    }]
                },
                "usage": {"image_count": 1},
                "request_id": "req-img-multi"
            }' )
            ->ensure( 'imagine' )
            ->imagine( 'combine these', [
                Image::fromUrl( 'https://example.com/a.jpg' ),
                Image::fromBase64( 'dGVzdA==', 'image/png' ),
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $content = $body['input']['messages'][0]['content'];
            $this->assertCount( 3, $content );
            $this->assertEquals( 'combine these', $content[0]['text'] );
            $this->assertEquals( 'https://example.com/a.jpg', $content[1]['image'] );
            $this->assertEquals( 'data:image/png;base64,dGVzdA==', $content[2]['image'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/edited.png', $file->url() );
    }


    public function testImagineZimage() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/zimg.png"
                            }]
                        }
                    }]
                },
                "usage": {
                    "image_count": 1,
                    "width": 1024,
                    "height": 1536
                },
                "request_id": "req-67890"
            }' )
            ->model( 'z-image-turbo' )
            ->ensure( 'imagine' )
            ->imagine( 'a sunset', [], ['size' => '1024*1536', 'seed' => 100, 'prompt_extend' => true, 'negative_prompt' => 'blurry', 'n' => 2, 'watermark' => true] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'z-image-turbo', $body['model'] );
            $this->assertEquals( 'a sunset', $body['input']['messages'][0]['content'][0]['text'] );
            $this->assertEquals( '1024*1536', $body['parameters']['size'] );
            $this->assertEquals( 100, $body['parameters']['seed'] );
            $this->assertTrue( $body['parameters']['prompt_extend'] );
            $this->assertArrayNotHasKey( 'negative_prompt', $body['parameters'] );
            $this->assertArrayNotHasKey( 'n', $body['parameters'] );
            $this->assertArrayNotHasKey( 'watermark', $body['parameters'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/zimg.png', $file->url() );
    }


    public function testVectorize() : void
    {
        $result = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "embeddings": [
                        {"index": 0, "embedding": [0.1, 0.2, 0.3], "type": "image"},
                        {"index": 1, "embedding": [0.4, 0.5, 0.6], "type": "image"}
                    ]
                },
                "usage": {
                    "input_tokens": 10,
                    "image_tokens": 896
                },
                "request_id": "req-vec-123"
            }' )
            ->ensure( 'vectorize' )
            ->vectorize( [
                Image::fromUrl( 'https://example.com/photo.jpg' ),
                Image::fromBase64( 'dGVzdA==', 'image/png' ),
            ], 1024, ['output_type' => 'dense'] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'authorization' ) );
            $this->assertEquals( 'https://dashscope-intl.aliyuncs.com/api/v1/services/embeddings/multimodal-embedding/multimodal-embedding', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'tongyi-embedding-vision-plus', $body['model'] );
            $this->assertEquals( 'https://example.com/photo.jpg', $body['input']['contents'][0]['image'] );
            $this->assertEquals( 'data:image/png;base64,dGVzdA==', $body['input']['contents'][1]['image'] );
            $this->assertEquals( 1024, $body['parameters']['dimension'] );
            $this->assertEquals( 'dense', $body['parameters']['output_type'] );
        } );

        $this->assertEquals( [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]], $result->vectors() );
        $this->assertEquals( ['request_id' => 'req-vec-123'], $result->meta() );
    }


    public function testImagineWan() : void
    {
        $file = $this->prisma( 'image', 'alibaba', ['api_key' => 'test'] )
            ->response( '{
                "output": {
                    "choices": [{
                        "finish_reason": "stop",
                        "message": {
                            "role": "assistant",
                            "content": [{
                                "image": "https://dashscope-result.oss-cn-beijing.aliyuncs.com/wan.png"
                            }]
                        }
                    }]
                },
                "usage": {
                    "image_count": 1,
                    "size": "1280*1280"
                },
                "request_id": "req-wan-123"
            }' )
            ->model( 'wan2.6-t2i' )
            ->ensure( 'imagine' )
            ->imagine( 'a mountain landscape', [], ['size' => '1104*1472', 'negative_prompt' => 'blurry', 'n' => 4, 'seed' => 55, 'prompt_extend' => true, 'watermark' => true] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'wan2.6-t2i', $body['model'] );
            $this->assertEquals( 'a mountain landscape', $body['input']['messages'][0]['content'][0]['text'] );
            $this->assertEquals( '1104*1472', $body['parameters']['size'] );
            $this->assertEquals( 'blurry', $body['parameters']['negative_prompt'] );
            $this->assertEquals( 4, $body['parameters']['n'] );
            $this->assertEquals( 55, $body['parameters']['seed'] );
            $this->assertTrue( $body['parameters']['prompt_extend'] );
            $this->assertTrue( $body['parameters']['watermark'] );
        } );

        $this->assertEquals( 'https://dashscope-result.oss-cn-beijing.aliyuncs.com/wan.png', $file->url() );
    }
}
