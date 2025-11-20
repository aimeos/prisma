<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\AWS;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends AWS implements Vectorize
{
    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $model = $this->modelName( 'amazon.titan-embed-image-v1' );
        $uri = "/model/$model/invoke";
        $promises = $vectors = [];

        foreach( $images as $index => $image )
        {
            $payload = json_encode( [
                "inputImage" => $image->base64(),
                "embeddingConfig" => [
                    "outputEmbeddingLength" => $size ?? 1024
                ]
            ] );

            $promises[$index] = $payload ? $this->client()->postAsync( $uri, [
                'headers' => $this->sign4( 'bedrock', $uri, $payload ),
                'body' => $payload,
            ] ) : null;
        }

        foreach( $promises as $index => $promise )
        {
            $response = $promise?->wait();
            $this->validate( $response );

            if( $response?->getStatusCode() === 200 ) {
                $vectors[$index] = json_decode( $response->getBody()->getContents(), false )?->embedding;
            }
        }

        return VectorResponse::fromVectors( $vectors );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        parent::validate( $response );

        switch( $response->getStatusCode() )
        {
            case 200: break;
            case 409:
            case 413: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $response->getReasonPhrase() );
            case 502:
            case 503:
            case 504: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $response->getReasonPhrase() );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $response->getReasonPhrase() );
        }
    }
}
