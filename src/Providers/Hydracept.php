<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\FileResponse;


abstract class Hydracept extends Base implements Cancel, Resume
{
    /** @var array<int, string> */
    private const META = ['capabilityKey', 'jobId', 'progress', 'status'];

    /** @var array<int, string> */
    private const STOP = ['inspect_error', 'retry_new', 'stop'];

    /** Default polling interval in seconds */
    private const RETRY = 5;

    /** Media type prefix of the artifacts returned by the provider */
    protected const PREFIX = '';

    private string $projectId = '';


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.hydracept.com' ) );
        $this->projectId = $this->config( $config, 'project_id' );

        $this->pollTimeout( $config );
    }


    public function cancel( string $jobId ) : void
    {
        $jobId = $this->jobId( $jobId );

        $response = $this->client()->post( 'v1/jobs/' . rawurlencode( $jobId ) . '/cancel' );
        $this->validate( $response );
    }


    public function resume( string $jobId ) : FileResponse
    {
        $jobId = $this->jobId( $jobId );

        return FileResponse::fromAsync( $this->poll( $jobId ), self::RETRY, $this->pollTimeout, $jobId );
    }


    /**
     * Downloads an artifact of a succeeded job.
     *
     * @param string $jobId Hydracept job identifier
     * @param mixed $artifact Artifact data of the job
     * @return File|null Downloaded file or NULL if it's of another media type, e.g. metadata
     *  (artifacts without or with a generic media type are downloaded too)
     */
    protected function artifact( string $jobId, mixed $artifact ) : ?File
    {
        $artifactId = is_array( $artifact ) ? $artifact['artifactId'] ?? null : null;
        $mediaType = is_array( $artifact ) ? $artifact['mediaType'] ?? null : null;
        $mediaType = is_string( $mediaType ) ? strtolower( $mediaType ) : $mediaType; // media types are case-insensitive
        $generic = in_array( $mediaType, [null, '', 'application/octet-stream'], true );

        // dot segments would escape the artifact endpoint when the URL is resolved
        if( !is_string( $artifactId ) || trim( $artifactId, '.' ) === ''
            || !$generic && ( !is_string( $mediaType ) || !str_starts_with( $mediaType, static::PREFIX ) )
        ) {
            return null;
        }

        // the media type of untyped or generic artifacts is detected from their content
        return $this->toFile( $this->download( $jobId, $artifactId ), $generic ? null : $mediaType );
    }


    /**
     * Downloads the artifacts of a succeeded job.
     *
     * All artifacts are downloaded before any is returned, so a failed download doesn't leave
     * a partially populated response behind which the next poll would add the files to again.
     *
     * @param string $jobId Hydracept job identifier
     * @param array<string, mixed> $data Job status data
     * @return array<int, File> Downloaded files
     */
    protected function artifacts( string $jobId, array $data ) : array
    {
        $artifacts = is_array( $data['artifacts'] ?? null ) ? $data['artifacts'] : [];
        $files = [];

        foreach( $artifacts as $artifact )
        {
            if( $file = $this->artifact( $jobId, $artifact ) ) {
                $files[] = $file;
            }
        }

        return $files;
    }


    /**
     * Builds the request body of a capability job.
     *
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options
     * @return array<string, mixed> Request body
     */
    protected function body( array $input, array $options ) : array
    {
        $body = ['input' => $input] + $this->allowed( $options, ['externalRef', 'metadata', 'tags'] );
        if( $this->projectId !== '' ) {
            $body['context'] = ['projectId' => $this->projectId];
        }

        if( $execution = $this->allowed( $options, ['billingMode', 'credentialSource', 'executionPreference', 'maximumCharge'] ) ) {
            $body['execution'] = $execution;
        }

        $body['idempotencyKey'] = is_string( $options['idempotencyKey'] ?? null ) && $options['idempotencyKey'] !== ''
            ? $options['idempotencyKey']
            : bin2hex( random_bytes( 16 ) );

        return $body;
    }


    /**
     * Downloads the content of an artifact.
     *
     * The response is streamed and reading stops after the maximum response size, so oversized
     * artifacts are rejected without loading them into memory.
     *
     * @param string $jobId Hydracept job identifier
     * @param string $artifactId Hydracept artifact identifier
     * @return string Artifact content
     * @throws FailedException If the artifact doesn't exist or exceeds the maximum response size
     */
    protected function download( string $jobId, string $artifactId ) : string
    {
        $response = $this->client()->get(
            'v1/jobs/' . rawurlencode( $jobId ) . '/artifacts/' . rawurlencode( $artifactId ),
            ['stream' => true]
        );
        // missing and oversized artifacts won't change, so the job fails instead of being polled again
        try {
            $this->validate( $response );
        } catch( NotFoundException $e ) {
            throw new FailedException( $e->getMessage() );
        }

        try {
            return $this->readBody( $response );
        } catch( PrismaException $e ) {
            throw new FailedException( $e->getMessage() );
        }
    }


    /**
     * Submits and polls a Hydracept capability job.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options including envelope fields
     * @return FileResponse Asynchronous file response
     */
    protected function generate( string $capability, array $input, array $options = [] ) : FileResponse
    {
        return $this->resume( $this->submit( $capability, $input, $options ) );
    }


    /**
     * Throws the failure reported for a job.
     *
     * @param array<string, mixed> $data Job status data
     * @return never
     * @throws FailedException Always
     */
    protected function jobFailed( array $data ) : never
    {
        throw new FailedException( $this->errorMessage( $data ) ?: 'Hydracept job failed' );
    }


    /**
     * Returns a polling closure for a Hydracept job.
     *
     * Statuses other than succeeded and the failed ones are considered pending, so the polling
     * deadline applies to new statuses too. Pending jobs are polled again after the interval
     * Hydracept asks for.
     *
     * @param string $jobId Hydracept job identifier
     * @return \Closure Polling closure
     */
    protected function poll( string $jobId ) : \Closure
    {
        return function( FileResponse $result ) use ( $jobId ) : bool {
            $response = $this->client()->get( 'v1/jobs/' . rawurlencode( $jobId ) );
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $result->withMeta( $this->allowed( $data, self::META ) + $result->meta()->all() );
            $this->usage( $result, $data );

            if( $this->failed( $data ) ) {
                $this->jobFailed( $data );
            }

            if( ( $data['status'] ?? null ) !== 'succeeded' ) {
                // the interval Hydracept asks for only applies to the next poll
                $wait = is_numeric( $data['pollAfterSeconds'] ?? null ) ? (int) $data['pollAfterSeconds'] : 0;
                $result->withRetry( $wait > 0 ? $wait : self::RETRY );

                return false;
            }

            if( !( $files = $this->artifacts( $jobId, $data ) ) ) {
                $this->jobFailed( $data );
            }

            foreach( $files as $file ) {
                $result->add( $file );
            }

            return true;
        };
    }


    /**
     * Encodes reference images for the capability input.
     *
     * @param array<int, \Aimeos\Prisma\Files\Image> $images Reference images
     * @return array<int, array<string, mixed>> Hydracept reference image entries
     */
    protected function referenceImages( array $images ) : array
    {
        $references = [];

        foreach( $images as $image )
        {
            $references[] = [
                'dataBase64' => (string) $image->base64(),
                'mimeType' => $image->mimeType() ?? 'image/png',
            ] + ( $image->filename() ? ['filename' => $image->filename()] : [] );
        }

        return $references;
    }


    /**
     * Submits a capability job.
     *
     * Hydracept prices the job when admitting it, so no quote is requested before.
     *
     * @param string $capability Capability key
     * @param array<string, mixed> $input Capability input object
     * @param array<string, mixed> $options Caller options
     * @return string Hydracept job identifier
     */
    protected function submit( string $capability, array $input, array $options ) : string
    {
        $response = $this->client()->post( 'v1/capabilities/' . rawurlencode( $capability ) . '/jobs', [
            'json' => $this->body( $input, $options ),
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
     * Creates a file object from downloaded artifact bytes.
     *
     * @param string $binary Artifact content
     * @param string|null $mediaType Artifact media type or NULL to detect it from the content
     * @return File File object
     */
    abstract protected function toFile( string $binary, ?string $mediaType ) : File;


    /**
     * Adds the costs and pricing of the job to the response.
     *
     * @param FileResponse $result Response being populated
     * @param array<string, mixed> $data Job status data
     */
    protected function usage( FileResponse $result, array $data ) : void
    {
        $used = $data['actualCost'] ?? null;
        $usage = $this->allowed( $data, ['actualCost', 'estimatedCost', 'pricing', 'receiptId'] );

        $result->withUsage( is_numeric( $used ) ? (float) $used : null, $usage );
    }


    /**
     * Returns whether the job failed or won't finish anymore.
     *
     * Jobs needing attention are only polled again if Hydracept asks for it and jobs in other
     * pending states fail if Hydracept advises to stop polling.
     *
     * @param array<string, mixed> $data Job status data
     * @return bool True if the job failed
     */
    private function failed( array $data ) : bool
    {
        $next = $data['nextAction'] ?? null;

        return match( $data['status'] ?? null ) {
            'succeeded' => false,
            'canceled', 'failed' => true,
            'needs_attention' => $next !== 'poll',
            default => in_array( $next, self::STOP, true ),
        };
    }
}
