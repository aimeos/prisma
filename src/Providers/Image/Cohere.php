<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Cohere as CohereBase;
use Aimeos\Prisma\Responses\VectorResponse;


class Cohere extends CohereBase implements Vectorize
{
    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $allowed = $this->allowed( $options, ['embedding_types', 'max_tokens', 'truncate', 'priority'] );
        $request = [
            'model' => $this->modelName( 'embed-v4.0' ),
            'inputs' => [],
            'input_type' => 'image',
            'output_dimension' => $size ?: 1536,
            ...$allowed,
        ] + ['embedding_types' => ['float']];

        foreach( $images as $image )
        {
            /** @var array<int, array<string, mixed>> $inputs */
            $inputs = $request['inputs'];
            $inputs[] = [
                'content' => [[
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => sprintf( 'data:%s;base64,%s', $image->mimeType(), $image->base64() )
                    ]
                ]]
            ];
            $request['inputs'] = $inputs;
        }

        $response = $this->client()->post( 'v2/embed', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<string, mixed> $embeddings */
        $embeddings = $data['embeddings'] ?? [];
        /** @var array<int, array<int, float>|null> $floatVectors */
        $floatVectors = $embeddings['float'] ?? [];

        /** @var array<string, mixed> $meta */
        $meta = $data['meta'] ?? [];
        /** @var array<string, mixed> $billedUnits */
        $billedUnits = $meta['billed_units'] ?? [];
        $used = $billedUnits['images'] ?? 0;

        return VectorResponse::fromVectors( $floatVectors )
            ->withUsage( is_numeric( $used ) ? (float) $used : 0, $billedUnits )
            ->withMeta( $meta );
    }
}
