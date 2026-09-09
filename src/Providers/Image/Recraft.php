<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Recraft extends Base implements Repaint
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://external.api.recraft.ai' ) );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->request( 'imageToImage', [
            'prompt' => $prompt,
            'image_url' => $this->imageUrl( $image ),
        ] + $this->generationOptions( $options, 'recraftv4_1' )
            + $this->allowed( $options, ['strength', 'random_seed'] ) + ['strength' => 0.5] );
    }


    /**
     * @param array<string, mixed> $options Provider options
     * @return array<string, mixed> Generation parameters
     */
    protected function generationOptions( array $options, string $default ) : array
    {
        return ['model' => $this->modelName( $default )] + $this->allowed( $options, [
            'n', 'style', 'style_id', 'style_match', 'response_format',
            'negative_prompt', 'text_layout', 'controls'
        ] );
    }


    protected function imageUrl( Image $image ) : string
    {
        return $image->url() ?? 'data:' . $image->mimeType() . ';base64,' . $image->base64();
    }


    /**
     * @param array<string, mixed> $data Request parameters
     */
    protected function request( string $endpoint, array $data ) : FileResponse
    {
        $response = $this->client()->post( 'v1/images/' . $endpoint, ['json' => $data] );
        $this->validate( $response );
        $result = $this->fromJson( $response );
        $entries = $result['data'] ?? ( isset( $result['image'] ) ? [$result['image']] : [] );

        if( !is_array( $entries ) || !$entries ) {
            throw new PrismaException( 'No image data found in Recraft response' );
        }

        $file = FileResponse::fromFiles( [] );

        foreach( $entries as $entry )
        {
            if( !is_array( $entry ) ) {
                throw new PrismaException( 'Invalid image data in Recraft response' );
            }

            if( is_string( $entry['b64_json'] ?? null ) && $entry['b64_json'] !== '' )
            {
                $binary = base64_decode( $entry['b64_json'], true );

                if( $binary === false || $binary === '' ) {
                    throw new PrismaException( 'Invalid base64 image in Recraft response' );
                }

                $file->add( Image::fromBinary( $binary ) );
            }
            elseif( is_string( $entry['url'] ?? null ) && $entry['url'] !== '' ) {
                $file->add( Image::fromUrl( $entry['url'] ) );
            }
            else {
                throw new PrismaException( 'No image data found in Recraft response' );
            }
        }

        return $file->withMeta( $result )->withUsage( is_numeric( $result['credits'] ?? null ) ? (float) $result['credits'] : null );
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( $response->getStatusCode() === 200 ) {
            return;
        }

        $result = $this->fromJson( $response );
        $error = $result['error'] ?? $result['message'] ?? $response->getReasonPhrase();

        if( is_array( $error ) ) {
            $error = $error['message'] ?? $response->getReasonPhrase();
        }

        $this->throw( $response->getStatusCode(), is_string( $error ) ? $error : $response->getReasonPhrase() );
    }
}
