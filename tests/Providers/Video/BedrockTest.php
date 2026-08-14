<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BedrockTest extends TestCase
{
    use MakesPrismaRequests;


    public function testImagineSilentlyKeepsOnlyStartImage() : void
    {
        $this->prisma( 'video', 'bedrock', [
            'api_key' => 'test',
            's3_uri' => 's3://bucket/jobs/one',
        ] )->response( ['invocationArn' => 'arn:aws:bedrock:video-1'], [], 202 );
        $this->response( [
            'status' => 'Completed',
            'outputDataConfig' => ['s3OutputDataConfig' => ['s3Uri' => 's3://bucket/jobs/one']],
        ] );

        $response = $this->provider()->imagine( 'prompt', [
            'start' => Image::fromBinary( 'START', 'image/png' ),
            'end' => Image::fromBinary( 'END', 'image/png' ),
            'references' => [Audio::fromBinary( 'AUDIO', 'audio/mpeg' )],
        ], ['duration' => 24] );

        $this->assertSame( 's3://bucket/jobs/one/output.mp4', $response->first()?->url() );
        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $this->assertSame( 'TEXT_VIDEO', $body['modelInput']['taskType'] );
        $this->assertSame( 6, $body['modelInput']['videoGenerationConfig']['durationSeconds'] );
        $this->assertSame( base64_encode( 'START' ), $body['modelInput']['textToVideoParams']['images'][0]['source']['bytes'] );
        $this->assertStringNotContainsString( base64_encode( 'END' ), (string) $this->requests()[0]->getBody() );
        $this->assertStringNotContainsString( base64_encode( 'AUDIO' ), (string) $this->requests()[0]->getBody() );
    }
}
