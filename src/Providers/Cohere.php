<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Cohere extends Base
{
    use CallsTools;
    use OpenaiApi;

    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.cohere.ai' ) );
    }


    /**
     * @param array<string, mixed> $request Embedding request
     * @param string $unit Billed unit to report as usage
     */
    protected function embed( array $request, string $unit ) : \Aimeos\Prisma\Responses\VectorResponse
    {
        $response = $this->client()->post( 'v2/embed', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<string, mixed> $embeddings */
        $embeddings = $data['embeddings'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = $embeddings['float'] ?? [];

        /** @var array<string, mixed> $meta */
        $meta = $data['meta'] ?? [];
        /** @var array<string, mixed> $billedUnits */
        $billedUnits = $meta['billed_units'] ?? [];
        $used = $billedUnits[$unit] ?? 0;

        return \Aimeos\Prisma\Responses\VectorResponse::fromVectors( $vectors )
            ->withUsage( is_numeric( $used ) ? (float) $used : 0, $billedUnits )
            ->withMeta( $meta );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $msg = @$this->fromJson( $response )['message'] ?: $response->getReasonPhrase();
            $this->throw( match( $status ) {
                413 => 400,
                498 => 403,
                default => $status,
            }, is_string( $msg ) ? $msg : '' );
        }
    }
}
