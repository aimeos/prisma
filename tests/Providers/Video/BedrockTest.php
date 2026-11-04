<?php

namespace Tests\Providers\Video;

use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class BedrockTest extends TestCase
{
    use MakesPrismaRequests;


    public function testDescribe() : void
    {
        $response = $this->prisma( 'video', 'bedrock', ['api_key' => 'test'] )
            ->response( [
                'output' => ['message' => ['content' => [
                    ['text' => 'a video description'],
                ]]],
                'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 10, 'outputTokens' => 3],
            ] )
            ->withMaxTokens( 200 )
            ->ensure( 'describe' )
            ->describe( Video::fromBinary( 'MP4', 'video/mp4' ), 'it', [
                'temperature' => 0.2,
                'duration' => 5,
            ] );

        $this->assertPrismaRequest( function( $request ) {
            $body = json_decode( (string) $request->getBody(), true );
            $content = $body['messages'][0]['content'];

            $this->assertSame( 'https://bedrock-runtime.us-west-2.amazonaws.com/model/us.amazon.nova-lite-v1:0/invoke', (string) $request->getUri() );
            $this->assertSame( 'messages-v1', $body['schemaVersion'] );
            $this->assertSame( 'mp4', $content[0]['video']['format'] );
            $this->assertSame( base64_encode( 'MP4' ), $content[0]['video']['source']['bytes'] );
            $this->assertStringContainsString( '"it"', $content[1]['text'] );
            $this->assertSame( 0.2, $body['inferenceConfig']['temperature'] );
            $this->assertSame( 200, $body['inferenceConfig']['maxTokens'] );
            $this->assertArrayNotHasKey( 'duration', $body['inferenceConfig'] );
        } );

        $this->assertSame( 'a video description', $response->text() );
        $this->assertSame( 13, $response->usage()->totalTokens() );
    }


    public function testDescribeUsesS3Location() : void
    {
        $this->prisma( 'video', 'bedrock', ['api_key' => 'test'] )
            ->response( ['output' => ['message' => ['content' => [['text' => 'description']]]]] )
            ->ensure( 'describe' )
            ->describe( Video::fromUrl( 's3://bucket/input.mov', 'video/quicktime' ), options: [
                'bucketOwner' => '123456789012',
            ] );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $video = $body['messages'][0]['content'][0]['video'];

        $this->assertSame( 'mov', $video['format'] );
        $this->assertSame( 's3://bucket/input.mov', $video['source']['s3Location']['uri'] );
        $this->assertSame( '123456789012', $video['source']['s3Location']['bucketOwner'] );
    }


    public function testImagine() : void
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
            'start' => Image::fromBinary( 'START', 'image/jpeg' ),
            'end' => Image::fromBinary( 'END', 'image/png' ),
            'references' => [Audio::fromBinary( 'AUDIO', 'audio/mpeg' )],
        ], ['duration' => 9, 'aspect_ratio' => '9:16', 'resolution' => '4k', 'loop' => 'yes'] );

        $this->assertSame( 's3://bucket/jobs/one/output.mp4', $response->first()?->url() );
        $this->assertSame( 'https://bedrock-runtime.us-west-2.amazonaws.com/async-invoke', (string) $this->requests()[0]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );
        $input = $body['modelInput'];

        $this->assertSame( 'luma.ray-v2:0', $body['modelId'] );
        $this->assertSame( 's3://bucket/jobs/one', $body['outputDataConfig']['s3OutputDataConfig']['s3Uri'] );
        $this->assertSame( 'prompt', $input['prompt'] );
        $this->assertSame( '9s', $input['duration'] );
        $this->assertSame( '9:16', $input['aspect_ratio'] );
        $this->assertArrayNotHasKey( 'resolution', $input );
        $this->assertArrayNotHasKey( 'loop', $input );
        $this->assertSame( 'image/jpeg', $input['keyframes']['frame0']['source']['media_type'] );
        $this->assertSame( base64_encode( 'START' ), $input['keyframes']['frame0']['source']['data'] );
        $this->assertSame( base64_encode( 'END' ), $input['keyframes']['frame1']['source']['data'] );
        $this->assertStringNotContainsString( base64_encode( 'AUDIO' ), (string) $this->requests()[0]->getBody() );
    }


    public function testImagineDefaults() : void
    {
        $this->prisma( 'video', 'bedrock', [
            'api_key' => 'test',
            's3_uri' => 's3://bucket/jobs',
            'url' => 'https://bedrock-runtime.example.com',
        ] )->response( ['invocationArn' => 'arn:aws:bedrock:video-1'], [], 202 );
        $this->response( ['status' => 'Completed'] );

        $this->provider()->imagine( 'prompt' );

        $this->assertSame( 'https://bedrock-runtime.example.com/async-invoke', (string) $this->requests()[0]->getUri() );

        $body = json_decode( (string) $this->requests()[0]->getBody(), true );

        $this->assertSame( ['prompt' => 'prompt', 'duration' => '5s'], $body['modelInput'] );
    }
}
