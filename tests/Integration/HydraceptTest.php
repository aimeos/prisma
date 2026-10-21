<?php

namespace Tests\Integration;

use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


/**
 * Live Hydracept integration tests.
 *
 * Set HYDRACEPT_API_KEY in .env (optionally HYDRACEPT_PROJECT, HYDRACEPT_API_URL
 * and HYDRACEPT_VOICE_ID) and run:
 *
 *   vendor/bin/phpunit -c phpunit-integration.xml tests/Integration/HydraceptTest.php
 */
class HydraceptTest extends TestCase
{
    public function testCancel() : void
    {
        $config = $this->config();

        $pending = Prisma::video()
            ->using( 'hydracept', $config )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street', [], ['duration' => 4] );

        $jobId = (string) $pending->jobId();
        $this->assertNotSame( '', $jobId );

        Prisma::video()->using( 'hydracept', $config )->ensure( 'cancel' )->cancel( $jobId );

        $this->expectException( FailedException::class );
        Prisma::video()->using( 'hydracept', $config )->resume( $jobId )->ready();
    }


    public function testCompose() : void
    {
        $response = Prisma::audio()
            ->using( 'hydracept', $this->config() )
            ->ensure( 'compose' )
            ->compose( 'Calm piano with soft strings', [
                'duration' => 5,
                'forceInstrumental' => true,
                'outputFormatPreference' => 'mp3_44100_128',
            ] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
        $this->assertSame( 'audio/mpeg', $response->mimeType() );
        $this->assertNotEmpty( $response->usage()['receiptId'] ?? null );

        file_put_contents( __DIR__ . '/results/hydracept_compose.mp3', $response->binary() );
    }


    public function testImagine() : void
    {
        $response = Prisma::image()
            ->using( 'hydracept', $this->config() )
            ->ensure( 'imagine' )
            ->imagine( 'A watercolor lighthouse at dusk', [], [
                'width' => 1024,
                'height' => 1024,
            ] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
        $this->assertStringStartsWith( 'image/', (string) $response->mimeType() );
        $this->assertNotEmpty( $response->usage()['receiptId'] ?? null );

        file_put_contents( __DIR__ . '/results/hydracept_imagine.png', $response->binary() );
    }


    public function testImagineVideo() : void
    {
        $response = Prisma::video()
            ->using( 'hydracept', $this->config() )
            ->ensure( 'imagine' )
            ->imagine( 'A paper boat crossing a rain-filled city street', [], [
                'duration' => 4,
                'aspectRatio' => '16:9',
            ] );

        $video = $response->first();
        $this->assertInstanceOf( Video::class, $video );
        $this->assertGreaterThan( 0, strlen( $video->binary() ) );
        $this->assertStringStartsWith( 'video/', (string) $video->mimeType() );

        file_put_contents( __DIR__ . '/results/hydracept_imagine.mp4', $video->binary() );
    }


    public function testResume() : void
    {
        $config = $this->config();

        $pending = Prisma::image()
            ->using( 'hydracept', $config )
            ->ensure( 'imagine' )
            ->imagine( 'A red balloon', [], ['width' => 1024, 'height' => 1024] );

        $jobId = (string) $pending->jobId();
        $this->assertNotSame( '', $jobId );

        $response = Prisma::image()
            ->using( 'hydracept', $config )
            ->ensure( 'resume' )
            ->resume( $jobId );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
    }


    public function testSpeak() : void
    {
        $response = Prisma::audio()
            ->using( 'hydracept', $this->config() )
            ->ensure( 'speak' )
            ->speak( 'This is a test.', $_ENV['HYDRACEPT_VOICE_ID'] ?? '21m00Tcm4TlvDq8ikWAM', [
                'outputFormatPreference' => 'mp3_44100_128',
            ] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
        $this->assertSame( 'audio/mpeg', $response->mimeType() );
        $this->assertNotEmpty( $response->usage()['receiptId'] ?? null );

        file_put_contents( __DIR__ . '/results/hydracept_speak.mp3', $response->binary() );
    }


    public function testSynthesize() : void
    {
        $response = Prisma::audio()
            ->using( 'hydracept', $this->config() )
            ->ensure( 'synthesize' )
            ->synthesize( 'Thunder rumbling in the distance', [
                'duration' => 2,
                'outputFormatPreference' => 'mp3_44100_128',
            ] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );
        $this->assertSame( 'audio/mpeg', $response->mimeType() );

        file_put_contents( __DIR__ . '/results/hydracept_synthesize.mp3', $response->binary() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->safeLoad();

        if( empty( $_ENV['HYDRACEPT_API_KEY'] ) ) {
            $this->markTestSkipped( 'HYDRACEPT_API_KEY is not defined in the environment' );
        }

        if( !is_dir( __DIR__ . '/results' ) ) {
            mkdir( __DIR__ . '/results', 0777, true );
        }
    }


    /**
     * @return array<string, string>
     */
    private function config() : array
    {
        $config = ['api_key' => $_ENV['HYDRACEPT_API_KEY']];

        if( !empty( $_ENV['HYDRACEPT_PROJECT'] ) ) {
            $config['project_id'] = $_ENV['HYDRACEPT_PROJECT'];
        }

        if( !empty( $_ENV['HYDRACEPT_API_URL'] ) ) {
            $config['url'] = $_ENV['HYDRACEPT_API_URL'];
        }

        return $config;
    }
}
