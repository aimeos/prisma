<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\HasImageResponse;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Xai as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Xai extends Base implements Imagine, Repaint
{
    use HasImageResponse;


    /**
     * Generates images or edits the supplied reference images.
     *
     * @param string $prompt Image description or editing instructions
     * @param array<int, Image> $images Source images, or an empty array for generation
     * @param array<string, mixed> $options Provider-specific image options
     * @return FileResponse Generated or edited images with usage and metadata
     */
    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        if( count( $images ) > 5 ) {
            throw new BadRequestException( 'xAI editing accepts at most five source images' );
        }

        $sources = [];

        foreach( $images as $image )
        {
            if( !( $image instanceof Image ) ) {
                throw new BadRequestException( 'xAI editing references must be Image objects' );
            }

            $sources[] = ['type' => 'image_url', 'url' => $image->url()
                ?? 'data:' . $image->mimeType() . ';base64,' . $image->base64()];
        }

        $request = [
            'model' => $this->modelName( $sources ? 'grok-imagine-image-2.0' : 'grok-imagine-image-quality' ),
            'prompt' => $prompt,
        ] + $this->allowed( $options, ['n', 'response_format', 'user', 'aspect_ratio', 'quality'] );

        if( count( $sources ) === 1 ) {
            $request['image'] = $sources[0];
        } elseif( $sources ) {
            $request['images'] = $sources;
        }

        $endpoint = $sources ? 'v1/images/edits' : 'v1/images/generations';
        $response = $this->client()->post( $endpoint, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    /**
     * Repaints a source image according to the prompt.
     *
     * @param Image $image Source image
     * @param string $prompt Description of the requested changes
     * @param array<string, mixed> $options Provider-specific editing options
     * @return FileResponse Edited images with usage and metadata
     */
    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->imagine( $prompt, [$image], $options );
    }
}
