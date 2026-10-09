<?php

namespace Tests\Responses;

use Aimeos\Prisma\Responses\DecisionResponse;
use Aimeos\Prisma\Values\Answer;
use PHPUnit\Framework\TestCase;


class DecisionResponseTest extends TestCase
{
    public function testAnswers() : void
    {
        $response = DecisionResponse::fromAnswers( [
            'spam' => ['type' => 'noul', 'noul' => 0.2],
            'team' => ['type' => 'choice', 'choice' => 'sales'],
        ] );

        $this->assertInstanceOf( Answer::class, $response->answer( 'spam' ) );
        $this->assertNull( $response->answer( 'missing' ) );
        $this->assertEquals( ['spam', 'team'], array_keys( $response->answers() ) );

        foreach( $response as $id => $answer ) {
            $this->assertInstanceOf( Answer::class, $answer );
        }
    }


    public function testJsonSerialize() : void
    {
        $response = DecisionResponse::fromAnswers( ['spam' => ['value' => 0.2, 'type' => 'noul']] )
            ->withUsage( 10, ['input_tokens' => 10] )
            ->withMeta( ['model' => 'jev-1.13.0'] );

        $this->assertEquals(
            '{"answers":{"spam":{"value":0.2,"type":"noul"}},"usage":{"used":10,"input_tokens":10},"meta":{"model":"jev-1.13.0"}}',
            json_encode( $response )
        );
    }
}
