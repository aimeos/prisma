<?php

namespace Tests\Providers;

use Tests\MakesPrismaRequests;


trait HydraceptJobResponses
{
    use MakesPrismaRequests;


    /**
     * Queues job submit, optional pending polls, and a succeeded job with one artifact download.
     *
     * @param string $type Prisma media type
     * @param string $capability Hydracept capability key
     * @param string $mediaType Artifact media type
     * @param string $binary Artifact body
     * @param array<string, mixed> $jobMeta Extra fields on the succeeded job payload
     */
    protected function hydraceptJob(
        string $type,
        string $capability,
        string $mediaType,
        string $binary,
        array $jobMeta = []
    ) : void {
        $this->prisma( $type, 'hydracept', ['api_key' => 'test-key', 'project_id' => 'proj-1'] )
            ->response( ['jobId' => 'job-1', 'status' => 'queued', 'capabilityKey' => $capability] );
        $this->response( ['jobId' => 'job-1', 'status' => 'running'] );
        $this->response( [
            'jobId' => 'job-1',
            'status' => 'succeeded',
            'receiptId' => 'rcpt-1',
            'actualCost' => 0.12,
            'pricing' => [
                'charge' => ['customerCharge' => [
                    'amountMicros' => 150000, 'amountMinor' => 15, 'currency' => 'USD', 'display' => 'US$0.15',
                ]],
                'providerCost' => ['amount' => [
                    'amountMicros' => 120000, 'amountMinor' => 12, 'currency' => 'USD', 'display' => 'US$0.12',
                ], 'status' => 'known', 'basis' => 'provider-reported'],
            ],
            'artifacts' => [['artifactId' => 'art-1', 'mediaType' => $mediaType, 'kind' => 'primary']],
        ] + $jobMeta );
        $this->response( $binary, ['Content-Type' => $mediaType] );
    }
}
