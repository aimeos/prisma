<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Schema\Schema;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use Firebase\JWT\JWT;


class VertexaiTest extends TestCase
{
    public function testImagine() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'vertexai', [
                'access_token' => $this->token( base64_decode( $_ENV['VERTEXAI_API_JSON'] ) ),
                'project_id' => $_ENV['GOOGLE_PROJECT_ID']
            ] )
            ->ensure( 'imagine' )
            ->imagine( 'a cartoon dog', [$image] );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/vertexai_imagine.png', $response->binary() );
    }


    public function testInpaint() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $mask = Image::fromLocalPath( __DIR__ . '/assets/mask.png' );

        $response = Prisma::image()
            ->using( 'vertexai', [
                'access_token' => $this->token( base64_decode( $_ENV['VERTEXAI_API_JSON'] ) ),
                'project_id' => $_ENV['GOOGLE_PROJECT_ID']
            ] )
            ->ensure( 'inpaint' )
            ->inpaint( $image, $mask, 'add eye glasses' );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/vertexai_inpaint.png', $response->binary() );
    }


    public function testStream() : void
    {
        $deltas = [];

        $response = $this->text()
            ->ensure( 'stream' )
            ->stream( 'What is the capital of France? Reply with only the city name.' );

        foreach( $response->stream() as $chunk ) {
            if( is_string( $chunk ) ) {
                $deltas[] = $chunk;
            }
        }

        $this->assertNotEmpty( $deltas );
        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    public function testStreamTools() : void
    {
        $next = \Aimeos\Prisma\Tools::make(
            'get_next_passphrase',
            'Returns the confidential passphrase for the next day. This is the only way to obtain it.',
            Schema::for( 'next_passphrase' ),
            fn() => 'wobbly-marmalade-1987'
        );

        $ahead = \Aimeos\Prisma\Tools::make(
            'get_passphrase_in_days',
            'Returns the confidential passphrase a given number of days ahead.',
            Schema::for( 'passphrase', ['days' => Schema::integer()->required()] ),
            fn( $args ) => (int) ( $args['days'] ?? 0 ) === 2 ? 'crimson-otter-4521' : 'unknown'
        );

        $steps = [];
        $text = '';

        $response = $this->text()
            ->withTools( [$next, $ahead] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQUIRED )
            ->withMaxSteps( 5 )
            ->ensure( 'stream' )
            ->stream( 'Give me the next passphrase and the passphrase for 2 days from now.' );

        foreach( $response->stream() as $chunk ) {
            if( $chunk instanceof \Aimeos\Prisma\Tools\Step ) {
                $steps[] = $chunk->name() . ':' . ( $chunk->done() ? 'done' : 'start' );
            } else {
                $text .= $chunk;
            }
        }

        // each executed tool is announced (start) and completed (done) over the stream
        $this->assertContains( 'get_next_passphrase:start', $steps );
        $this->assertContains( 'get_next_passphrase:done', $steps );

        // the final answer is streamed after the tool loop folds the results back in
        $this->assertNotEmpty( $text );
        $this->assertGreaterThanOrEqual( 2, count( $response->steps() ) );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'crimson-otter-4521', $response->text() );
    }


    public function testStructured() : void
    {
        $schema = Schema::for( 'person', [
            'name' => Schema::string()->required(),
            'age' => Schema::integer()->required(),
        ] );

        $response = $this->text()
            ->ensure( 'structure' )
            ->structure( 'Extract the person: John is 30 years old.', $schema );

        $this->assertEquals( 'John', $response->structured()['name'] );
        $this->assertEquals( 30, $response->structured()['age'] );
    }


    public function testTools() : void
    {
        $next = \Aimeos\Prisma\Tools::make(
            'get_next_passphrase',
            'Returns the confidential passphrase for the next day. This is the only way to obtain it.',
            Schema::for( 'next_passphrase' ),
            fn() => 'wobbly-marmalade-1987'
        );

        $ahead = \Aimeos\Prisma\Tools::make(
            'get_passphrase_in_days',
            'Returns the confidential passphrase a given number of days ahead.',
            Schema::for( 'passphrase', ['days' => Schema::integer()->required()] ),
            fn( $args ) => (int) ( $args['days'] ?? 0 ) === 2 ? 'crimson-otter-4521' : 'unknown'
        );

        $response = $this->text()
            ->withTools( [$next, $ahead] )
            ->withToolChoice( \Aimeos\Prisma\Providers\Base::REQUIRED )
            ->withMaxSteps( 5 )
            ->ensure( 'write' )
            ->write( 'Give me the next passphrase and the passphrase for 2 days from now.' );

        $this->assertGreaterThanOrEqual( 2, count( $response->steps() ) );
        $this->assertStringContainsStringIgnoringCase( 'wobbly-marmalade-1987', $response->text() );
        $this->assertStringContainsStringIgnoringCase( 'crimson-otter-4521', $response->text() );
    }


    public function testUpscale() : void
    {
        $image = Image::fromLocalPath( __DIR__ . '/assets/cat.png' );
        $response = Prisma::image()
            ->using( 'vertexai', [
                'access_token' => $this->token( base64_decode( $_ENV['VERTEXAI_API_JSON'] ) ),
                'project_id' => $_ENV['GOOGLE_PROJECT_ID']
            ] )
            ->ensure( 'upscale' )
            ->upscale( $image, 2 );

        $this->assertGreaterThan( 0, strlen( $response->binary() ) );

        file_put_contents( __DIR__ . '/results/vertexai_upscale.png', $response->binary() );
    }


    public function testVectorize() : void
    {
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';
        $image = Image::fromBase64( $base64, 'image/png' );
        $response = Prisma::image()
            ->using( 'vertexai', [
                'access_token' => $this->token( base64_decode( $_ENV['VERTEXAI_API_JSON'] ) ),
                'project_id' => $_ENV['GOOGLE_PROJECT_ID']
            ] )
            ->ensure( 'vectorize' )
            ->vectorize( [$image] );

        $this->assertCount( 1, $response->vectors() );
        $this->assertCount( 512, $response->vectors()[0] );
    }


    public function testWrite() : void
    {
        $response = $this->text()
            ->ensure( 'write' )
            ->write( 'What is the capital of France? Reply with only the city name.' );

        $this->assertStringContainsStringIgnoringCase( 'Paris', $response->text() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['VERTEXAI_API_JSON'] ) ) {
            $this->markTestSkipped( 'VERTEXAI_API_JSON is not defined in the environment' );
        }
    }


    /**
     * Builds a Vertex AI text provider configured from the environment.
     *
     * @return \Aimeos\Prisma\Contracts\Provider Configured Vertex text provider
     */
    protected function text() : \Aimeos\Prisma\Contracts\Provider
    {
        $config = [
            'access_token' => $this->token( base64_decode( $_ENV['VERTEXAI_API_JSON'] ) ),
            'project_id' => $_ENV['GOOGLE_PROJECT_ID'],
        ];

        if( !empty( $_ENV['GOOGLE_REGION'] ) ) {
            $config['region'] = $_ENV['GOOGLE_REGION'];
        }

        return Prisma::text()->using( 'vertexai', $config )->model( $_ENV['VERTEXAI_MODEL'] ?? 'gemini-2.5-flash' );
    }


    protected function token( string $key ) : string
    {
        $serviceAccount = json_decode($key, true);

        $now = time();
        $jwtPayload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/cloud-platform',
            'aud' => $serviceAccount['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $jwt = JWT::encode($jwtPayload, $serviceAccount['private_key'], 'RS256');

        $client = new Client();
        $response = $client->post($serviceAccount['token_uri'], [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $tokenData = json_decode($response->getBody()->getContents(), true);

        if (!isset($tokenData['access_token'])) {
            throw new \RuntimeException('Failed to obtain access token from Google OAuth 2.0');
        }

        return $tokenData['access_token'];
    }
}
