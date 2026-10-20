<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\FileResponse;


abstract class Hydracept extends Base implements Resume
{
    protected int $pollTimeout = 900;

    private string $projectId = '';

    /** @var array<int, string> */
    private const TERMINAL_FAILURE = ['failed', 'canceled', 'canceling'];

    /** @var array<int, string> */
    private const PENDING = ['queued', 'running', 'awaiting_approval'];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.hydracept.com' ) );
        $this->projectId = $this->config( $config, 'project_id', $this->config( $config, 'projectId', '' ) );

        $timeout = $config['poll_timeout'] ?? null;

        if( is_int( $timeout ) && $timeout >= 0 ) {
            $this->pollTimeout = $timeout;
        } elseif( is_string( $timeout ) && preg_match( '/^\d+$/D', $timeout ) ) {
            $this->pollTimeout = (int) $timeout;
        }
    }


    public function resume( string $jobId ) : FileResponse
    {
        $jobId = $this->jobId( $jobId );

        return FileResponse::fromAsync( $this->poll( $jobId ), 5, $this->pollTimeout, $jobId );
    }


    /**
     * Quotes, submits, and polls a Hydracept capability job.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options including envelope fields
     * @return FileResponse Asynchronous file response
     */
    protected function generate(
        string $capability,
        array $input,
        array $options = []
    ) : FileResponse {
        $quoteId = $this->quote( $capability, $input, $options );
        $jobId = $this->submit( $capability, $input, $quoteId, $options );

        return $this->resume( $jobId )->withMeta( ['quoteId' => $quoteId] );
    }


    /**
     * Returns a factory that builds file objects from downloaded artifact bytes.
     *
     * @return callable(string, string): File File factory
     */
    abstract protected function artifactFactory() : callable;


    /**
     * Returns a polling closure for a Hydracept job.
     *
     * @param string $jobId Hydracept job identifier
     * @return \Closure Polling closure
     */
    protected function poll( string $jobId ) : \Closure
    {
        $factory = $this->artifactFactory();

        return function( FileResponse $result ) use ( $jobId, $factory ) : bool {
            $response = $this->client()->get( 'v1/jobs/' . rawurlencode( $jobId ) );
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $result->withMeta( $this->jobMeta( $data ) + $result->meta()->all() );
            $this->applyUsage( $result, $data );

            $status = $data['status'] ?? null;

            if( $status === 'needs_attention' ) {
                throw new PrismaException( $this->errorMessage( $data ) ?: 'Hydracept job needs attention' );
            }

            if( in_array( $status, self::TERMINAL_FAILURE, true ) ) {
                $this->jobFailed( $data );
            }

            if( !in_array( $status, [...self::PENDING, 'succeeded'], true ) ) {
                throw new PrismaException( $this->errorMessage( $data ) ?: 'Unknown Hydracept job status' );
            }

            if( $status !== 'succeeded' ) {
                return false;
            }

            $this->attachArtifacts( $result, $jobId, $data, $factory );

            if( $result->empty() ) {
                $this->jobFailed( $data );
            }

            return true;
        };
    }


    /**
     * Requests a retail quote before job submission.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options
     * @return string Quote identifier for sealed admission
     */
    protected function quote( string $capability, array $input, array $options ) : string
    {
        $response = $this->client()->post( 'v1/capabilities/' . rawurlencode( $capability ) . '/quote', [
            'json' => $this->envelope( $input, $options, false ),
        ] );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        if( ( $data['status'] ?? 'ok' ) !== 'ok' ) {
            $missing = is_array( $data['missing'] ?? null ) ? implode( ', ', $data['missing'] ) : '';
            throw new PrismaException( $missing !== ''
                ? 'Hydracept quote requires: ' . $missing
                : 'Hydracept quote unavailable' );
        }

        $quoteId = $data['quoteId'] ?? $data['estimateId'] ?? null;

        if( !is_string( $quoteId ) || $quoteId === '' ) {
            throw new PrismaException( 'No Hydracept quote ID in response' );
        }

        return $quoteId;
    }


    /**
     * Submits a capability job with sealed quote admission.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param string $quoteId Quote identifier from quote()
     * @param array<string, mixed> $options Caller options
     * @return string Hydracept job identifier
     */
    protected function submit( string $capability, array $input, string $quoteId, array $options ) : string
    {
        $body = $this->envelope( $input, $options, true ) + [
            'idempotencyKey' => $this->idempotencyKey( $options ),
            'execution' => ['quoteId' => $quoteId] + $this->execution( $options ),
        ];

        $response = $this->client()->post( 'v1/capabilities/' . rawurlencode( $capability ) . '/jobs', [
            'json' => $body,
        ] );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $jobId = $data['jobId'] ?? null;

        if( !is_string( $jobId ) || $jobId === '' ) {
            throw new PrismaException( $this->errorMessage( $data ) ?: 'No Hydracept job ID in response' );
        }

        return $jobId;
    }


    /**
     * Builds the Hydracept capability request envelope.
     *
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options
     * @param bool $withCorrelation Whether to include correlation fields on the job body
     * @return array<string, mixed> Request envelope
     */
    protected function envelope( array $input, array $options, bool $withCorrelation ) : array
    {
        $request = ['input' => $input];
        $context = $this->context( $options );

        if( !empty( $context ) ) {
            $request['context'] = $context;
        }

        $execution = $this->execution( $options );

        if( !empty( $execution ) ) {
            $request['execution'] = $execution;
        }

        if( !$withCorrelation ) {
            return $request;
        }

        foreach( ['externalRef' => 'external_ref', 'tags' => 'tags', 'metadata' => 'metadata'] as $target => $source )
        {
            if( isset( $options[$source] ) ) {
                $request[$target] = $options[$source];
            } elseif( isset( $options[$target] ) ) {
                $request[$target] = $options[$target];
            }
        }

        return $request;
    }


    /**
     * @param array<string, mixed> $options Caller options
     * @return array<string, mixed> Capability context
     */
    protected function context( array $options ) : array
    {
        $projectId = $options['project_id'] ?? $options['projectId'] ?? $this->projectId;

        return is_string( $projectId ) && $projectId !== '' ? ['projectId' => $projectId] : [];
    }


    /**
     * @param array<string, mixed> $options Caller options
     * @return array<string, mixed> Execution options
     */
    protected function execution( array $options ) : array
    {
        $execution = [];
        $map = [
            'billing_mode' => 'billingMode',
            'billingMode' => 'billingMode',
            'execution_preference' => 'executionPreference',
            'executionPreference' => 'executionPreference',
            'credential_source' => 'credentialSource',
            'credentialSource' => 'credentialSource',
            'maximum_charge' => 'maximumCharge',
            'maximumCharge' => 'maximumCharge',
        ];

        foreach( $map as $source => $target )
        {
            if( isset( $options[$source] ) ) {
                $execution[$target] = $options[$source];
            }
        }

        return $execution;
    }


    /**
     * @param array<string, mixed> $options Caller options
     * @return string Idempotency key
     */
    protected function idempotencyKey( array $options ) : string
    {
        $key = $options['idempotency_key'] ?? $options['idempotencyKey'] ?? null;

        if( is_string( $key ) && $key !== '' ) {
            return $key;
        }

        return bin2hex( random_bytes( 16 ) );
    }


    /**
     * Removes envelope keys from capability options.
     *
     * @param array<string, mixed> $options All options
     * @param array<int, string> $allowed Allowed capability input keys
     * @return array<string, mixed> Capability input options
     */
    protected function capabilityOptions( array $options, array $allowed ) : array
    {
        return $this->allowed( $options, $allowed );
    }


    /**
     * @param array<int, \Aimeos\Prisma\Files\Image> $images Reference images
     * @return array<int, array<string, mixed>> Hydracept reference image entries
     */
    protected function referenceImages( array $images ) : array
    {
        $references = [];

        foreach( $images as $index => $image )
        {
            $entry = [
                'dataBase64' => (string) $image->base64(),
                'mimeType' => $image->mimeType() ?? 'image/png',
            ];

            if( $name = $image->filename() ) {
                $entry['filename'] = $name;
            } else {
                $entry['filename'] = 'reference-' . $index . '.png';
            }

            $references[] = $entry;
        }

        return $references;
    }


    /**
     * @param array<string, mixed> $data Job status data
     * @return array<string, mixed> Metadata for the response
     */
    protected function jobMeta( array $data ) : array
    {
        $meta = $data;
        unset( $meta['artifacts'] );

        return $meta;
    }


    /**
     * @param FileResponse $result Response being populated
     * @param array<string, mixed> $data Job status data
     */
    protected function applyUsage( FileResponse $result, array $data ) : void
    {
        $used = $data['actualCost'] ?? null;
        $usage = [];

        if( isset( $data['actualCost'] ) ) {
            $usage['actualCost'] = $data['actualCost'];
        }

        if( isset( $data['estimatedCost'] ) ) {
            $usage['estimatedCost'] = $data['estimatedCost'];
        }

        if( isset( $data['pricing'] ) && is_array( $data['pricing'] ) ) {
            $usage['pricing'] = $data['pricing'];
        }

        if( isset( $data['receiptId'] ) ) {
            $usage['receiptId'] = $data['receiptId'];
        }

        $result->withUsage( is_numeric( $used ) ? (float) $used : null, $usage );
    }


    /**
     * @param FileResponse $result Response being populated
     * @param string $jobId Hydracept job identifier
     * @param array<string, mixed> $data Job status data
     * @param callable(string, string): File|null $factory File factory
     */
    protected function attachArtifacts(
        FileResponse $result,
        string $jobId,
        array $data,
        ?callable $factory
    ) : void {
        if( !$factory ) {
            throw new BadRequestException( 'Missing Hydracept artifact factory' );
        }

        /** @var array<int, mixed> $artifacts */
        $artifacts = is_array( $data['artifacts'] ?? null ) ? $data['artifacts'] : [];

        foreach( $artifacts as $artifact )
        {
            if( !is_array( $artifact ) ) {
                continue;
            }

            $artifactId = $artifact['artifactId'] ?? null;

            if( !is_string( $artifactId ) || $artifactId === '' ) {
                continue;
            }

            $mediaType = is_string( $artifact['mediaType'] ?? null ) && $artifact['mediaType'] !== ''
                ? $artifact['mediaType']
                : 'application/octet-stream';

            $response = $this->client()->get(
                'v1/jobs/' . rawurlencode( $jobId ) . '/artifacts/' . rawurlencode( $artifactId )
            );
            $this->validate( $response );

            $binary = $response->getBody()->getContents();
            $file = $factory( $binary, $mediaType );

            $filename = $artifact['filename'] ?? null;

            if( is_string( $filename ) && $filename !== '' ) {
                $file->as( $filename );
            }

            $result->add( $file );
        }
    }


    /**
     * @param array<string, mixed> $data Job status data
     * @return never
     */
    protected function jobFailed( array $data ) : never
    {
        throw new FailedException( $this->errorMessage( $data ) ?: 'Hydracept job failed' );
    }
}
