<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class GroqTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'image', 'groq', ['api_key' => 'test'] )
            ->response( '{
                "id": "resp_abc123",
                "status": "completed",
                "model": "qwen/qwen3.6-27b",
                "output": [{
                    "type": "message",
                    "role": "assistant",
                    "content": [{
                        "type": "output_text",
                        "text": "an image description"
                    }]
                }],
                "usage": {
                    "total_tokens": 154
                }
            }' )
            ->ensure( 'describe' )
            ->describe( Image::fromBinary( 'PNG', 'image/png' ), 'en' );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.groq.com/openai/v1/responses', (string) $request->getUri() );
            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'qwen/qwen3.6-27b', $body['model'] );
            $this->assertEquals( 'input_image', $body['input'][0]['content'][1]['type'] );
        } );

        $this->assertEquals( 'an image description', $response->text() );
    }
}
