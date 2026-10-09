<?php

namespace Tests\Values;

use Aimeos\Prisma\Values\Answer;
use PHPUnit\Framework\TestCase;


class AnswerTest extends TestCase
{
    public function testAccessors() : void
    {
        $answer = new Answer( [
            'value' => 'billing',
            'type' => 'choice',
            'probabilities' => ['billing' => 0.88, 'sales' => 0.12],
            'confidence' => 0.81,
        ] );

        $this->assertSame( 'choice', $answer->type() );
        $this->assertSame( 'billing', $answer->value() );
        $this->assertSame( ['billing' => 0.88, 'sales' => 0.12], $answer->probabilities() );
        $this->assertSame( 0.81, $answer->confidence() );
    }


    public function testNumericValue() : void
    {
        $answer = new Answer( ['value' => 1] );

        $this->assertSame( 1.0, $answer->value() );
    }


    public function testMissing() : void
    {
        $answer = new Answer( ['legend' => ['0' => 'Calm']] );

        $this->assertNull( $answer->type() );
        $this->assertNull( $answer->value() );
        $this->assertNull( $answer->confidence() );
        $this->assertSame( [], $answer->probabilities() );
        $this->assertSame( ['0' => 'Calm'], $answer['legend'] );
    }
}
