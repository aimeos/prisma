<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Bedrock extends Base implements Vectorize
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $region = $config['region'] ?? 'us-east-1';

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( 'https://bedrock-runtime.' . $region . '.amazonaws.com' );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $promises = $vectors = [];
        $uri = 'model/' . $this->modelName( 'amazon.titan-embed-image-v1' ) . '/invoke';

        foreach( $images as $index => $image )
        {
            $promises[$index] = $this->client()->postAsync( $uri, [
                'json' => [
                    "inputImage" => $image->base64(),
                    "embeddingConfig" => [
                        "outputEmbeddingLength" => $size ?? 1024
                    ]
                ],
            ] );
        }

        foreach( $promises as $index => $promise )
        {
            $response = $promise->wait();
            $this->validate( $response );

            $vectors[$index] = json_decode( $response->getBody()->getContents(), false )?->embedding;
        }

        return VectorResponse::fromVectors( $vectors );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $error = json_decode( $response->getBody()->getContents() )?->error ?: $response->getReasonPhrase();

        switch( $response->getStatusCode() )
        {
            case 400:
            case 409:
            case 413: throw new \Aimeos\Prisma\Exceptions\BadRequestException( $error );
            case 401: throw new \Aimeos\Prisma\Exceptions\UnauthorizedException( $error );
            case 402: throw new \Aimeos\Prisma\Exceptions\PaymentRequiredException( $error );
            case 403: throw new \Aimeos\Prisma\Exceptions\ForbiddenException( $error );
            case 404: throw new \Aimeos\Prisma\Exceptions\NotFoundException( $error );
            case 429: throw new \Aimeos\Prisma\Exceptions\RateLimitException( $error );
            case 502:
            case 503:
            case 504: throw new \Aimeos\Prisma\Exceptions\OverloadedException( $error );
            default: throw new \Aimeos\Prisma\Exceptions\PrismaException( $error );
        }
    }
}
