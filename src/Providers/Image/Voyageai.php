<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Vectorize;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\VectorResponse;
use Psr\Http\Message\ResponseInterface;


class Voyageai extends Base implements Vectorize
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Content-Type', 'application/json' );
        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseurl( 'https://api.voyageai.com' );
    }


    public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $allowed = $this->allowed( $options, ['output_encoding', 'truncation'] );
        $request = [
            'model' => $this->modelName( 'voyage-multimodal-3.5' ),
            'inputs' => [],
            ...$allowed
        ];

        foreach( $images as $image )
        {
            /** @var array<int, array<string, mixed>> $inputs */
            $inputs = $request['inputs'];
            $inputs[] = [
                'content' => [[
                    'type' => 'image_base64',
                    'image_base64' => sprintf( 'data:%s;base64,%s', $image->mimeType(), $image->base64() )
                ]]
            ];
            $request['inputs'] = $inputs;
        }

        $response = $this->client()->post( 'v1/multimodalembeddings', ['json' => $request] );

        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );

        /** @var array<int, array<string, mixed>> $dataItems */
        $dataItems = $data['data'] ?? [];
        /** @var array<int, array<int, float>|null> $vectors */
        $vectors = array_map( fn( $item ) => $item['embedding'] ?? null, $dataItems );

        /** @var array<string, mixed> $usage */
        $usage = $data['usage'] ?? [];
        $used = $usage['total_tokens'] ?? 0;

        return VectorResponse::fromVectors( $vectors )
            ->withUsage( is_numeric( $used ) ? (float) $used : 0, $usage );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $error = @$this->fromJson( $response )['detail'] ?: $response->getReasonPhrase();
            $this->throw( $status, is_string( $error ) ? $error : '' );
        }
    }
}
