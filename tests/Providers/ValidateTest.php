<?php

namespace Tests\Providers;

use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\OverloadedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Exceptions\UnauthorizedException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Files\Image;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class ValidateTest extends TestCase
{
    use MakesPrismaRequests;


    #[TestWith( [401, UnauthorizedException::class] )]
    #[TestWith( [404, NotFoundException::class] )]
    #[TestWith( [429, RateLimitException::class] )]
    #[TestWith( [503, OverloadedException::class] )]
    public function testNonJsonErrorBody( int $status, string $exception ) : void
    {
        // gateways and proxies in front of the provider answer with HTML pages instead of JSON
        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( '<html><body>Access denied</body></html>', ['Content-Type' => 'text/html'], $status, 'Gateway says no' );

        // the status code decides the exception, an undecodable body only costs the message
        $this->expectException( $exception );
        $this->expectExceptionMessage( 'Gateway says no' );
        $this->provider()->write( 'prompt' );
    }


    public function testNonJsonErrorKeepsRetryAfter() : void
    {
        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( 'error 1015: rate limited', ['Retry-After' => '42'], 429 );

        try {
            $this->provider()->write( 'prompt' );
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 42, $e->retryAfter() );
        }
    }


    public function testOversizedErrorBody() : void
    {
        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )
            ->response( str_repeat( 'x', 2048 ), [], 503 );

        // reading the body is bounded, but that must not hide the status of the response
        $this->expectException( OverloadedException::class );
        $this->provider()->withMaxResponseSize( 16 )->write( 'prompt' );
    }


    public function testUnreadableErrorBody() : void
    {
        $provider = $this->prisma( 'text', 'openai', ['api_key' => 'test'] )->provider();
        $body = FnStream::decorate( Utils::streamFor( '{}' ), [
            'getContents' => fn() => throw new \RuntimeException( 'Connection reset' ),
        ] );

        // a failure reading the error body must not hide the status of the response
        try {
            ( fn() => $this->validate( new Response( 429, ['Retry-After' => '5'], $body ) ) )->call( $provider );
            $this->fail( 'RateLimitException expected' );
        } catch( RateLimitException $e ) {
            $this->assertSame( 5, $e->retryAfter() );
        }
    }


    #[TestWith( [['error' => 'Task not found'], 'Task not found'] )]
    #[TestWith( [['error' => ['message' => 'Rate limit reached']], 'Rate limit reached'] )]
    #[TestWith( [['message' => 'Invalid model'], 'Invalid model'] )]
    #[TestWith( [['error' => ['type' => 'invalid_request']], 'Bad Request'] )]
    #[TestWith( [['error' => ''], 'Bad Request'] )]
    #[TestWith( [['error' => ['code' => 'quota'], 'message' => 'Quota exceeded'], 'Quota exceeded'] )]
    #[TestWith( [['error' => '', 'message' => 'Quota exceeded'], 'Quota exceeded'] )]
    #[TestWith( [['error' => true, 'message' => 'Quota exceeded'], 'Quota exceeded'] )]
    #[TestWith( [['statusCode' => 400, 'error' => 'Bad Request', 'message' => 'Prompt too long'], 'Prompt too long'] )]
    #[TestWith( [[], 'Bad Request'] )]
    public function testErrorMessage( array $body, string $message ) : void
    {
        $this->prisma( 'text', 'openai', ['api_key' => 'test'] )->response( $body, [], 400, 'Bad Request' );

        $this->expectException( PrismaException::class );
        $this->expectExceptionMessage( $message );
        $this->provider()->write( 'prompt' );
    }


    #[TestWith( ['audiopod', ['detail' => [['msg' => 'field required'], 'x', ['msg' => ['x']], ['msg' => 'too long']]], 'field required, too long'] )]
    #[TestWith( ['audiopod', ['detail' => [], 'message' => 'Invalid input'], 'Invalid input'] )]
    #[TestWith( ['audiopod', ['detail' => ['code' => 'LIMIT_REACHED', 'message' => 'Voice limit reached']], 'Voice limit reached'] )]
    #[TestWith( ['audiopod', ['detail' => '', 'error' => 'Invalid input'], 'Invalid input'] )]
    #[TestWith( ['audiopod', ['detail' => ['msg' => 'field required']], 'Bad Request'] )]
    #[TestWith( ['blackforestlabs', ['detail' => [['msg' => 'field required'], 'x', ['msg' => ['x']], ['msg' => 'too long']]], 'field required, too long'] )]
    #[TestWith( ['blackforestlabs', ['detail' => [['msg' => ['x']]]], 'Bad Request'] )]
    #[TestWith( ['blackforestlabs', ['detail' => ['message' => 'Insufficient credits']], 'Insufficient credits'] )]
    #[TestWith( ['deepgram', ['err_msg' => ['x']], 'Bad Request'] )]
    #[TestWith( ['deepgram', ['err_code' => 'Bad Request', 'err_msg' => 'Invalid audio'], 'Invalid audio'] )]
    #[TestWith( ['deepgram', ['category' => 'INVALID_JSON', 'message' => 'Invalid JSON submitted.'], 'Invalid JSON submitted.'] )]
    #[TestWith( ['elevenlabs', ['detail' => ['message' => ['x']]], 'Bad Request'] )]
    #[TestWith( ['elevenlabs', ['detail' => [['msg' => 'field required']]], 'field required'] )]
    #[TestWith( ['gemini', ['error' => ['message' => 123]], 'Bad Request'] )]
    #[TestWith( ['recraft', ['error' => ['message' => 'Invalid prompt']], 'Invalid prompt'] )]
    #[TestWith( ['recraft', ['error' => ['code' => 'invalid']], 'Bad Request'] )]
    #[TestWith( ['removebg', ['errors' => [['title' => 'Invalid image'], 'unknown', ['title' => ['x']]]], 'Invalid image'] )]
    #[TestWith( ['removebg', ['errors' => 'Invalid image'], 'Bad Request'] )]
    #[TestWith( ['stabilityai', ['errors' => ['Invalid prompt', ['nested']]], 'Invalid prompt'] )]
    #[TestWith( ['stabilityai', ['errors' => 'Invalid prompt'], 'Bad Request'] )]
    #[TestWith( ['voyageai', ['detail' => [['msg' => 'field required']]], 'field required'] )]
    #[TestWith( ['voyageai', ['detail' => 'Invalid input'], 'Invalid input'] )]
    public function testProviderErrorMessage( string $name, array $body, string $message ) : void
    {
        // unexpected error formats cost the message only instead of causing PHP errors
        $type = match( $name ) {
            'audiopod', 'deepgram', 'elevenlabs' => 'audio',
            'gemini' => 'text',
            default => 'image',
        };

        $prisma = $this->prisma( $type, $name, ['api_key' => 'test'] );
        $prisma->response( $body, [], 400, 'Bad Request' );

        try {
            match( $name ) {
                'audiopod' => $this->provider()->denoise( Audio::fromBinary( 'MP3', 'audio/mpeg' ) ),
                'deepgram' => $this->provider()->transcribe( Audio::fromBinary( 'MP3', 'audio/mpeg' ) ),
                'elevenlabs' => $this->provider()->speak( 'text', 'voice' ),
                'gemini' => $this->provider()->write( 'prompt' ),
                'blackforestlabs', 'recraft', 'stabilityai' => $this->provider()->imagine( 'prompt' ),
                'removebg' => $this->provider()->isolate( Image::fromBinary( 'PNG', 'image/png' ) ),
                'voyageai' => $this->provider()->vectorize( [Image::fromBinary( 'PNG', 'image/png' )] ),
            };
            $this->fail( 'PrismaException expected' );
        } catch( PrismaException $e ) {
            $this->assertSame( $message, $e->getMessage() );
        }
    }
}
