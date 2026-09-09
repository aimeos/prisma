<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\UnauthorizedException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Image\Adobe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class AdobeTest extends TestCase
{
    use MakesPrismaRequests;

    private const SOURCE = 'https://bucket.s3.amazonaws.com/source.png';
    private const STATUS = 'https://firefly-api.adobe.io/v3/status/job';


    #[DataProvider('methods')]
    public function testMethods( string $method, array $args, string $endpoint, array $expected, string $model = '' ) : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        $this->response( ['statusUrl' => self::STATUS], [], 202 );

        $provider->ensure( $method )->$method( ...$args );
        $request = $this->requests()[0];
        $this->assertSame( 'POST', $request->getMethod() );
        $this->assertSame( 'https://firefly-api.adobe.io/' . $endpoint, (string) $request->getUri() );
        $this->assertSame( 'Bearer token', $request->getHeaderLine( 'Authorization' ) );
        $this->assertSame( 'client', $request->getHeaderLine( 'x-api-key' ) );
        $this->assertSame( $model, $request->getHeaderLine( 'x-model-version' ) );
        $this->assertEquals( $expected, json_decode( (string) $request->getBody(), true ) );
    }


    public function testPollingLifecycle() : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        $this->response( ['statusUrl' => self::STATUS, 'jobId' => 'job'], ['retry-after' => '2'], 202 );
        $this->response( ['status' => 'running'] );
        $this->response( ['status' => 'succeeded', 'result' => ['altText' => 'Result', 'outputs' => [
            ['seed' => 0, 'image' => ['url' => 'https://example.com/a.png']],
            ['seed' => 1, 'image' => ['url' => 'https://example.com/b.png']],
        ]]] );

        $result = $provider->imagine( 'Scene' );
        $this->assertFalse( $result->ready() );
        $this->assertTrue( $result->ready() );
        $this->assertCount( 2, iterator_to_array( $result ) );
        $this->assertSame( 'https://example.com/a.png', $result->url() );
        $this->assertSame( 'Result', $result->description() );
        $this->assertSame( 'job', $result->meta()['jobId'] );
        $this->assertSame( self::STATUS, (string) $this->requests()[1]->getUri() );
        $this->assertSame( 'Bearer token', $this->requests()[1]->getHeaderLine( 'Authorization' ) );
    }


    public static function methods() : array
    {
        $image = Image::fromUrl( self::SOURCE );
        $source = ['source' => ['url' => self::SOURCE]];
        return [
            'imagine' => ['imagine', ['Scene', [], ['seeds' => [0], 'unknown' => true, 'prompt' => 'ignored']], 'v4/images/generate-async',
                ['prompt' => 'Scene', 'modelId' => 'firefly_image', 'seeds' => [0]], 'image5'],
            'reference' => ['imagine', ['Edit', [3 => $image]], 'v4/images/generate-async',
                ['prompt' => 'Edit', 'modelId' => 'firefly_image', 'referenceBlobs' => [$source + ['usage' => 'general']]], 'image5'],
            'repaint' => ['repaint', [$image, 'Edit'], 'v4/images/generate-async',
                ['prompt' => 'Edit', 'modelId' => 'firefly_image', 'referenceBlobs' => [$source + ['usage' => 'general']]], 'image5'],
            'background' => ['background', [$image, 'Forest', ['numVariations' => 2]], 'v3/images/generate-object-composite-async',
                ['prompt' => 'Forest', 'image' => $source, 'numVariations' => 2]],
            'masked background' => ['background', [$image, 'Forest', ['mask' => $image]], 'v3/images/generate-object-composite-async',
                ['prompt' => 'Forest', 'image' => $source, 'mask' => $source]],
            'inpaint' => ['inpaint', [$image, $image, 'Glasses', ['invert' => false, 'unknown' => 1]], 'v3/images/fill-async',
                ['prompt' => 'Glasses', 'image' => $source, 'mask' => $source + ['invert' => false]]],
            'precise relocate' => ['relocate', [$image, $image, ['fillAreaMask' => $image, 'blend' => 0, 'harmonization' => 1]], 'v3/images/precise-composite',
                ['background' => ['image' => $source, 'fillAreaMask' => $source], 'object' => ['image' => $source], 'blend' => 0]],
            'adaptive relocate' => ['relocate', [$image, $image, ['fillAreaMask' => $image, 'mask' => $image, 'mode' => 'adaptive', 'preserveBackground' => false]], 'v3/images/adaptive-composite',
                ['background' => ['image' => $source, 'fillAreaMask' => $source], 'object' => ['image' => $source, 'mask' => $source], 'preserveBackground' => false]],
            'upscale' => ['upscale', [$image, 4, ['upscaleFactor' => 2]], 'v3/images/upscale',
                ['image' => $source, 'upscaleFactor' => 4, 'seeds' => [0]], 'precise_upsampler_v1'],
        ];
    }


    public function testUploadAndUncrop() : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        $image = Image::fromLocalPath( __DIR__ . '/../../Integration/assets/cat.png' );
        $size = getimagesizefromstring( $image->binary() );
        $this->response( ['images' => [['id' => 'upload']]] );
        $this->response( ['links' => ['result' => ['href' => self::STATUS]]], [], 202 );
        $provider->ensure( 'uncrop' )->uncrop( $image, 10, 20, 30, 40, ['size' => ['width' => 1], 'prompt' => 'Extend'] );

        $requests = $this->requests();
        $this->assertSame( '/v2/storage/image', $requests[0]->getUri()->getPath() );
        $this->assertSame( 'image/png', $requests[0]->getHeaderLine( 'Content-Type' ) );
        $this->assertSame( $image->binary(), (string) $requests[0]->getBody() );
        $this->assertSame( '/v3/images/expand-async', $requests[1]->getUri()->getPath() );
        $this->assertEquals( [
            'image' => ['source' => ['uploadId' => 'upload']],
            'size' => ['width' => $size[0] + 60, 'height' => $size[1] + 40],
            'placement' => ['inset' => ['top' => 10, 'right' => 20, 'bottom' => 30, 'left' => 40]], 'prompt' => 'Extend'
        ], json_decode( (string) $requests[1]->getBody(), true ) );
    }


    public function testImage4StyleReferenceAndCustomUrl() : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client', 'url' => 'https://gateway.example'] )->provider();
        $this->response( ['statusUrl' => 'https://gateway.example/poll'] );
        $provider->model( 'image4_standard' )->imagine( 'Scene', [Image::fromUrl( self::SOURCE )], ['style' => ['strength' => 50], 'resolutionLevel' => '4MP'] );
        $request = $this->requests()[0];
        $this->assertSame( 'https://gateway.example/v3/images/generate-async', (string) $request->getUri() );
        $this->assertSame( 'image4_standard', $request->getHeaderLine( 'x-model-version' ) );
        $this->assertEquals( ['prompt' => 'Scene', 'style' => ['strength' => 50, 'imageReference' => ['source' => ['url' => self::SOURCE]]]],
            json_decode( (string) $request->getBody(), true ) );
    }


    public function testBinaryInpaintAndLinkedJob() : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        $this->response( ['images' => [['id' => 'source']]] );
        $this->response( ['images' => [['id' => 'mask']]] );
        $this->response( ['links' => ['result' => ['href' => self::STATUS]]], [], 202 );
        $this->response( ['status' => 'succeeded', 'result' => ['outputs' => [['image' => ['url' => self::SOURCE]]]]] );
        $result = $provider->inpaint( Image::fromBinary( 'SOURCE', 'image/png' ), Image::fromBinary( 'MASK', 'image/png' ), 'Edit' );
        $requests = $this->requests();
        $this->assertSame( 'SOURCE', (string) $requests[0]->getBody() );
        $this->assertSame( 'MASK', (string) $requests[1]->getBody() );
        $this->assertSame( 'Bearer token', $requests[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( 'client', $requests[0]->getHeaderLine( 'x-api-key' ) );
        $this->assertEquals( ['prompt' => 'Edit', 'image' => ['source' => ['uploadId' => 'source']], 'mask' => ['source' => ['uploadId' => 'mask']]],
            json_decode( (string) $requests[2]->getBody(), true ) );
        $this->assertTrue( $result->ready() );
        $this->assertSame( self::SOURCE, $result->url() );
        $this->assertNull( $result->description() );
    }


    #[DataProvider('invalidInputs')]
    public function testInvalidInputs( string $method, array $args ) : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        try {
            $provider->$method( ...$args );
            $this->fail( 'Expected invalid input rejection' );
        } catch( BadRequestException $e ) {
            $this->assertSame( [], $this->requests() );
        }
    }


    public static function invalidInputs() : array
    {
        $image = Image::fromBinary( 'PNG', 'image/png' );
        return [
            ['imagine', ['Scene', [$image, $image]]],
            ['imagine', ['Scene', ['invalid']]],
            ['imagine', ['Scene', [$image], ['aspectRatio' => '16:9']]],
            ['background', [$image, 'Forest', ['mask' => 'invalid']]],
            ['background', [$image, 'Forest', ['mask' => $image, 'placement' => []]]],
            ['relocate', [$image, $image]],
            ['relocate', [$image, $image, ['fillAreaMask' => $image, 'mode' => 'unknown']]],
            ['relocate', [$image, $image, ['fillAreaMask' => $image, 'mask' => $image]]],
            ['uncrop', [$image, -1, 0, 0, 0]],
            ['uncrop', [$image, 0, 0, 0, 0]],
            ['uncrop', [$image, 4000, 0, 0, 0]],
            ['upscale', [$image, 5]],
        ];
    }


    #[DataProvider('invalidResponses')]
    public function testInvalidResponses( array $responses, string $method = 'imagine' ) : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        foreach( $responses as $response ) {
            $this->response( $response );
        }

        $this->expectException( PrismaException::class );
        $result = $method === 'imagine' ? $provider->imagine( 'Scene' ) : $provider->upscale( Image::fromBinary( 'PNG', 'image/png' ), 2 );
        $result->ready();
    }


    public static function invalidResponses() : array
    {
        $job = ['statusUrl' => self::STATUS];
        return [
            'missing job' => [[[]]],
            'foreign poll host' => [[['statusUrl' => 'https://attacker.example/poll']]],
            'insecure poll' => [[['statusUrl' => 'http://firefly-api.adobe.io/poll']]],
            'foreign poll port' => [[['statusUrl' => 'https://firefly-api.adobe.io:8443/poll']]],
            'poll user info' => [[['statusUrl' => 'https://user@firefly-api.adobe.io/poll']]],
            'malformed poll URL' => [[['statusUrl' => 'https://firefly-api.adobe.io:invalid/poll']]],
            'failure' => [[$job, ['status' => 'failed']]],
            'canceled' => [[$job, ['status' => 'canceled']]],
            'unknown status' => [[$job, ['status' => 'unknown']]],
            'empty result' => [[$job, ['status' => 'succeeded', 'result' => ['outputs' => []]]]],
            'invalid image' => [[$job, ['status' => 'succeeded', 'result' => ['outputs' => [['image' => ['url' => '']]]]]]],
            'missing upload' => [[['images' => []]], 'upscale'],
        ];
    }


    #[DataProvider('httpStages')]
    public function testHttpError( string $stage ) : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();

        if( $stage === 'poll' ) {
            $this->response( ['statusUrl' => self::STATUS], [], 202 );
        }

        $this->response( ['message' => 'Expired token'], [], 401 );
        $this->expectException( UnauthorizedException::class );
        $this->expectExceptionMessage( 'Expired token' );
        $result = $stage === 'upload' ? $provider->upscale( Image::fromBinary( 'PNG', 'image/png' ), 2 ) : $provider->imagine( 'Scene' );
        $result->ready();
    }


    public static function httpStages() : array
    {
        return [['submit'], ['upload'], ['poll']];
    }


    public function testRepaintRejectsOtherModels() : void
    {
        $provider = $this->prisma( 'image', 'adobe', ['api_key' => 'token', 'client_id' => 'client'] )->provider();
        $this->expectException( BadRequestException::class );
        $provider->model( 'image3' )->repaint( Image::fromUrl( self::SOURCE ), 'Edit' );
    }


    #[DataProvider('invalidConfig')]
    public function testCredentialsRequired( array $config ) : void
    {
        $this->expectException( PrismaException::class );
        new Adobe( $config );
    }


    public static function invalidConfig() : array
    {
        return [[[]], [['api_key' => 'token']], [['client_id' => 'client']], [['api_key' => '', 'client_id' => 'client']]];
    }
}
