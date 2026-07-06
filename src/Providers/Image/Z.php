<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Z as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Z extends Base implements Imagine
{
    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $options = $this->sanitize(
            $this->allowed( $options, ['quality', 'size', 'user_id'] ),
            ['quality' => ['hd', 'standard']]
        );

        $response = $this->client()->post( 'api/paas/v4/images/generations', [
            'json' => ['model' => $this->modelName( 'glm-image' ), 'prompt' => $prompt] + $options
        ] );

        return $this->toFileResponse( $response );
    }


    /**
     * Converts the HTTP response into a file response.
     *
     * @param ResponseInterface $response HTTP response
     * @return FileResponse Image response
     */
    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );

        /** @var array<string, mixed> $result */
        $result = $this->fromJson( $response );
        $files = [];

        /** @var array<int, array<string, mixed>> $dataItems */
        $dataItems = $result['data'] ?? [];

        foreach( $dataItems as $item )
        {
            if( !empty( $item['b64_json'] ) ) {
                /** @var string $b64 */
                $b64 = $item['b64_json'];
                $files[] = Image::fromBase64( $b64 );
            } elseif( !empty( $item['url'] ) ) {
                /** @var string $url */
                $url = $item['url'];
                $files[] = Image::fromUrl( $url );
            }
        }

        if( empty( $files ) ) {
            throw new PrismaException( 'No image data found in response' );
        }

        $meta = $result;
        unset( $meta['data'], $meta['usage'] );

        /** @var array<string, mixed> $usage */
        $usage = $result['usage'] ?? [];
        $used = $usage['total_tokens'] ?? null;

        return FileResponse::fromFiles( $files )
            ->withUsage(
                is_numeric( $used ) ? (float) $used : null,
                $usage,
            )
            ->withMeta( $meta );
    }
}

