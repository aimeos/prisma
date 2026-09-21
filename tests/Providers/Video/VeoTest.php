<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class VeoTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineDropsReferencesWhenFramesAreUsed() : void
    {
        $this->prisma( 'video', 'veo', ['api_key' => 'test'] )
            ->response( ['name' => 'operations/video-1'] );
        $this->response( [
            'done' => true,
            'response' => ['generateVideoResponse' => ['generatedSamples' => [[
                'video' => ['uri' => 'https://example.com/video.mp4'],
            ]]]],
        ] );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'prompt', [
            'start' => Image::fromBinary( 'START', 'image/png' ),
            'end' => Image::fromBinary( 'END', 'image/png' ),
            'references' => [Image::fromBinary( 'REFERENCE', 'image/png' )],
        ] );

        $this->assertSame( 'https://example.com/video.mp4', $response->first()?->url() );
        $request = $this->requests()[0];
        $body = json_decode( (string) $request->getBody(), true );
        $this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models/veo-3.1-generate-preview:predictLongRunning', (string) $request->getUri() );
        $this->assertArrayHasKey( 'image', $body['instances'][0] );
        $this->assertArrayHasKey( 'lastFrame', $body['instances'][0] );
        $this->assertArrayNotHasKey( 'referenceImages', $body['instances'][0] );
    }


    public function testRateLimitRetryHeader() : void
    {
        // without RetryInfo in the body, e.g. from a proxy, the Retry-After header is used
        $this->prisma( 'video', 'veo', ['api_key' => 'test'] )
            ->response( ['error' => ['code' => 429, 'message' => 'Resource exhausted']], ['Retry-After' => '7'], 429, 'Too Many Requests' );

        try {
            $this->provider()->imagine( 'prompt' );
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 7, $e->retryAfter() );
        }
    }
}
