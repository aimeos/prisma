<?php

namespace Tests\Providers\Text;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\OverloadedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Exceptions\RateLimitException;
use Aimeos\Prisma\Exceptions\UnauthorizedException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\MakesPrismaRequests;


class TypesafeTest extends TestCase
{
    use MakesPrismaRequests;


    public function testNoApiKey() : void
    {
        $this->expectException( PrismaException::class );

        $this->prisma( 'text', 'typesafe', [] );
    }


    public function testDecide() : void
    {
        $response = $this->prisma( 'text', 'typesafe', ['api_key' => 'test'] )
            ->response( [
                'model' => 'jev-1.13.0',
                'answers' => [
                    'urgent' => ['type' => 'noul', 'noul' => 0.95],
                    'team' => [
                        'type' => 'choice',
                        'choice' => 'billing',
                        'probabilities' => ['billing' => 0.88, 'technical' => 0.12],
                        'confidence' => 0.81,
                    ],
                    'anger' => [
                        'type' => 'score',
                        'score' => 1.05,
                        'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'],
                        'probabilities' => ['0' => 0.0, '1' => 0.95, '2' => 0.05],
                        'confidence' => 0.92,
                    ],
                ],
                'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
            ] )
            ->ensure( 'decide' )
            ->decide( 'Help! My payouts have been failing for 3 days.', [
                'urgent' => ['type' => 'noul', 'instructions' => 'Does this convey urgency?'],
                'team' => ['type' => 'choice', 'instructions' => 'Which team?', 'criteria' => ['billing' => 'Payments', 'technical' => null]],
                'anger' => ['type' => 'score', 'instructions' => 'How frustrated?', 'criteria' => ['Calm', 'Frustrated', 'Very angry']],
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://api.typesafe.ai/v1/systemone', (string) $request->getUri() );
            $this->assertEquals( 'POST', $request->getMethod() );
            $this->assertEquals( 'Bearer test', $request->getHeaderLine( 'Authorization' ) );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( 'Help! My payouts have been failing for 3 days.', $body['state'] );
            $this->assertEquals( 'jev-latest', $body['model'] );
            $this->assertEquals( ['type' => 'noul', 'instructions' => 'Does this convey urgency?'], $body['questions']['urgent'] );
            $this->assertEquals( ['billing' => 'Payments', 'technical' => null], $body['questions']['team']['criteria'] );
            $this->assertEquals( ['Calm', 'Frustrated', 'Very angry'], $body['questions']['anger']['criteria'] );
        } );

        $this->assertEqualsWithDelta( 0.95, $response->answer( 'urgent' )?->value(), 0.0001 );
        $this->assertNull( $response->answer( 'urgent' )?->confidence() );
        $this->assertEquals( 'billing', $response->answer( 'team' )?->value() );
        $this->assertEqualsWithDelta( 0.81, $response->answer( 'team' )?->confidence(), 0.0001 );
        $this->assertEqualsWithDelta( 1.05, $response->answer( 'anger' )?->value(), 0.0001 );
        $this->assertEquals( 'Frustrated', $response->answer( 'anger' )['legend']['1'] ?? null );
        $this->assertEquals( ['urgent', 'team', 'anger'], array_keys( $response->answers() ) );
        $this->assertEquals( 300, $response->usage()->promptTokens() );
        $this->assertEquals( 20, $response->usage()->completionTokens() );
        $this->assertEquals( 320, $response->usage()->used() );
        $this->assertEquals( 'jev-1.13.0', $response->meta()->model() );
    }


    public function testDecideNumericIds() : void
    {
        $response = $this->prisma( 'text', 'typesafe', ['api_key' => 'test'] )
            ->response( ['model' => 'jev-1.13.0', 'answers' => ['0' => ['type' => 'noul', 'noul' => 0.1]]] )
            ->ensure( 'decide' )
            ->decide( 'text', [['type' => 'noul', 'instructions' => 'Is it spam?']] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertStringContainsString( '"questions":{"0":{', (string) $request->getBody() );
        } );

        $this->assertEqualsWithDelta( 0.1, $response->answer( '0' )?->value(), 0.0001 );
    }


    public function testDecideStructuredState() : void
    {
        $this->prisma( 'text', 'typesafe', ['api_key' => 'test', 'url' => 'https://proxy.example.com'] )
            ->response( ['model' => 'jev-1.13.0', 'answers' => [], 'usage' => []] )
            ->model( 'jev-1.13.0' )
            ->ensure( 'decide' )
            ->decide( ['customer' => 'Jane', 'messages' => ['Hi', 'Refund please']], [
                'refund' => [
                    'type' => 'noul',
                    'instructions' => ['question' => 'Does `customer` ask for a refund?'],
                    'criteria' => ['true' => 'Explicit refund request'],
                ],
            ] );

        $this->assertPrismaRequest( function( $request, $options ) {
            $this->assertEquals( 'https://proxy.example.com/v1/systemone', (string) $request->getUri() );

            $body = json_decode( $request->getBody()->getContents(), true );
            $this->assertEquals( ['customer' => 'Jane', 'messages' => ['Hi', 'Refund please']], $body['state'] );
            $this->assertEquals( 'jev-1.13.0', $body['model'] );
            $this->assertEquals( ['question' => 'Does `customer` ask for a refund?'], $body['questions']['refund']['instructions'] );
        } );
    }


    #[TestWith( [401, UnauthorizedException::class] )]
    #[TestWith( [422, BadRequestException::class] )]
    #[TestWith( [429, RateLimitException::class] )]
    #[TestWith( [529, OverloadedException::class] )]
    public function testDecideError( int $status, string $exception ) : void
    {
        $this->expectException( $exception );

        $this->prisma( 'text', 'typesafe', ['api_key' => 'test'] )
            ->response( ['detail' => 'Error'], status: $status )
            ->ensure( 'decide' )
            ->decide( 'text', ['spam' => ['type' => 'noul', 'instructions' => 'Is it spam?']] );
    }
}
