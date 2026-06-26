<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Responses\VectorResponse;


/**
 * Vertex AI text provider (Gemini models via Google Cloud Vertex AI).
 *
 * Reuses the Gemini request/response handling but swaps the public Generative
 * Language endpoint and API-key header for Vertex's project/region path and
 * OAuth bearer token.
 */
class Vertexai extends Gemini
{
    private string $projectid;
    private string $region;


    public function __construct( array $config )
    {
        if( !isset( $config['access_token'] ) ) {
            throw new PrismaException( 'No access token' );
        }

        if( !isset( $config['project_id'] ) ) {
            throw new PrismaException( 'No Google project ID' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'access_token' ) );

        $region = $this->config( $config, 'region' );
        $this->baseUrl( 'https://' . ( $region !== '' ? $region . '-' : '' ) . 'aiplatform.googleapis.com' );

        $this->region = $region !== '' ? $region : 'global';
        $this->projectid = $this->config( $config, 'project_id' );
    }


    public function vectorize( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $model = $this->modelName( 'gemini-embedding-001' );
        $allowed = $this->allowed( $options, ['task_type', 'title'] );

        $instances = array_map( fn( string $text ) => ['content' => $text] + $allowed, array_values( $texts ) );
        $request = ['instances' => $instances] + ( $size ? ['parameters' => ['outputDimensionality' => $size]] : [] );

        $response = $this->client()->post( $this->modelPath( $model ) . ':predict', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> $predictions */
        $predictions = $data['predictions'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = array_map( fn( $entry ) => $entry['embeddings']['values'] ?? null, $predictions );

        return VectorResponse::fromVectors( $vectors );
    }


    protected function generateEndpoint( ?string $model ) : string
    {
        return $this->modelPath( $model ) . ':generateContent';
    }


    protected function streamEndpoint( ?string $model ) : string
    {
        return $this->modelPath( $model ) . ':streamGenerateContent?alt=sse';
    }


    /**
     * Builds the Vertex AI publisher model path for the configured project and region.
     *
     * @param string|null $model Model name
     * @return string Model resource path
     */
    private function modelPath( ?string $model ) : string
    {
        return 'v1/projects/' . $this->projectid . '/locations/' . $this->region . '/publishers/google/models/' . $model;
    }
}
