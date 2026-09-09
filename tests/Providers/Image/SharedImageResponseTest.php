<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Exceptions\PrismaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class SharedImageResponseTest extends TestCase
{
    use MakesPrismaRequests;


    #[DataProvider('providers')]
    public function testImagesAndMetadata( string $provider ) : void
    {
        $base64 = base64_encode( 'image bytes' );
        $usage = ['total_tokens' => '12', 'input_tokens' => 2];
        $url = 'https://example.com/image.png';
        $response = $this->prisma( 'image', $provider, ['api_key' => 'test'] )
            ->response( ['data' => [['b64_json' => $base64, 'url' => $url], ['url' => $url], []],
                'usage' => $usage, 'created' => 123] )
            ->imagine( 'A fox' );

        $this->assertCount( 2, $response->files() );
        $this->assertSame( $base64, $response->files()[0]->base64() );
        $this->assertNull( $response->files()[0]->url() );
        $this->assertSame( $url, $response->files()[1]->url() );
        $this->assertSame( ['used' => 12.0] + $usage, $response->usage()->all() );
        $this->assertSame( ['created' => 123], $response->meta()->all() );
    }


    #[DataProvider('providers')]
    public function testMissingUsage( string $provider ) : void
    {
        $response = $this->prisma( 'image', $provider, ['api_key' => 'test'] )
            ->response( ['data' => [['url' => 'https://example.com/image.png']]] )
            ->imagine( 'A fox' );

        $this->assertNull( $response->usage()['used'] );
        $this->assertSame( [], $response->meta()->all() );
    }


    #[DataProvider('providers')]
    public function testEmptyImages( string $provider ) : void
    {
        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( 'No image data found in response' );

        $this->prisma( 'image', $provider, ['api_key' => 'test'] )
            ->response( ['data' => [[]]] )->imagine( 'A fox' );
    }


    public static function providers() : array
    {
        return ['openai' => ['openai'], 'xai' => ['xai'], 'z' => ['z']];
    }
}
