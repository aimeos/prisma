<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class QwenTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagine() : void
    {
        $file = $this->prisma( 'image', 'qwen', ['api_key' => 'test'] )
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
}
