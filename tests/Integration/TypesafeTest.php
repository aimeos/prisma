<?php

namespace Tests\Integration;

use Aimeos\Prisma\Prisma;
use PHPUnit\Framework\TestCase;


class TypesafeTest extends TestCase
{
    public function testDecide() : void
    {
        $response = Prisma::text()
            ->using( 'typesafe', ['api_key' => $_ENV['TYPESAFE_API_KEY']] )
            ->ensure( 'decide' )
            ->decide( 'Help! My payouts have been failing for 3 days.', [
                'urgent' => ['type' => 'noul', 'instructions' => 'Does this convey urgency?'],
                'team' => ['type' => 'choice', 'instructions' => 'Which team should handle this?', 'criteria' => [
                    'billing' => 'Payments, invoicing, refunds',
                    'technical' => 'Bugs, outages, integrations',
                    'sales' => 'Pricing, upgrades, new accounts',
                ]],
                'anger' => ['type' => 'score', 'instructions' => 'How frustrated is the customer?', 'criteria' => ['Calm', 'Frustrated', 'Very angry']],
            ] );

        $this->assertGreaterThan( 0.5, $response->answer( 'urgent' )?->value() );
        $this->assertContains( $response->answer( 'team' )?->value(), ['billing', 'technical', 'sales'] );
        $this->assertNotNull( $response->answer( 'team' )?->confidence() );
        $this->assertIsFloat( $response->answer( 'anger' )?->value() );
        $this->assertNotEmpty( $response->meta()->model() );
        $this->assertGreaterThan( 0, $response->usage()->promptTokens() );
    }


    protected function setUp() : void
    {
        \Dotenv\Dotenv::createImmutable( dirname( __DIR__, 2 ) )->load();

        if( empty( $_ENV['TYPESAFE_API_KEY'] ) ) {
            $this->markTestSkipped( 'TYPESAFE_API_KEY is not defined in the environment' );
        }
    }
}
