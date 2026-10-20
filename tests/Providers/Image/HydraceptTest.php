<?php

namespace Tests\Providers\Image;

use Aimeos\Prisma\Files\Image;
use PHPUnit\Framework\TestCase;
use Tests\Providers\HydraceptJobResponses;


class HydraceptTest extends TestCase
{
    use HydraceptJobResponses;


    public function testImagine() : void
    {
        $this->hydraceptJob( 'image', 'image.generate.v1', 'image/png', 'PNG' );

        $response = $this->provider()->ensure( 'imagine' )->imagine( 'A red balloon', [
            Image::fromBinary( 'REF', 'image/jpeg' ),
        ], [
            'width' => 1024,
            'height' => 768,
            'quality' => 'high',
            'outputFormatPreference' => 'ignored',
            'idempotency_key' => 'idem-1',
        ] );

        $quote = json_decode( (string) $this->requests()[0]->getBody(), true );
        $job = json_decode( (string) $this->requests()[1]->getBody(), true );

        $this->assertSame(
            'https://api.hydracept.com/v1/capabilities/image.generate.v1/quote',
            (string) $this->requests()[0]->getUri()
        );
        $this->assertSame( 'Bearer test-key', $this->requests()[0]->getHeaderLine( 'Authorization' ) );
        $this->assertSame( 'proj-1', $quote['context']['projectId'] );
        $this->assertSame( 'A red balloon', $quote['input']['prompt'] );
        $this->assertSame( 1024, $quote['input']['width'] );
        $this->assertArrayHasKey( 'referenceImages', $quote['input'] );
        $this->assertSame( 'idem-1', $job['idempotencyKey'] );
        $this->assertSame( 'quote-1', $job['execution']['quoteId'] );
        $this->assertArrayNotHasKey( 'outputFormatPreference', $quote['input'] );

        $this->assertSame( 'PNG', $response->binary() );
        $this->assertSame( 'job-1', $response->jobId() );
        $this->assertSame( 'rcpt-1', $response->meta()['receiptId'] );
        $this->assertSame( 'quote-1', $response->meta()['quoteId'] );
        $this->assertSame( 0.12, $response->usage()['used'] );
    }
}
