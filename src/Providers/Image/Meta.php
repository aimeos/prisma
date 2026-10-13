<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\HasImageResponse;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Meta as Base;
use Aimeos\Prisma\Responses\FileResponse;


class Meta extends Base implements Imagine, Repaint
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
        $request = [
            'model' => $this->modelName( 'muse-image-1.0' ),
            'prompt' => $prompt,
        ] + $this->allowed( $options, ['n', 'size', 'response_format', 'output_format', 'reasoning_strength', 'moderation', 'user'] );

        if( $images ) {
            $request['images'] = $this->sources( $images );
        }

        $endpoint = $images ? 'v1/images/edits' : 'v1/images/generations';
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


    /**
     * Builds the image input items for the edits endpoint.
     *
     * @param array<int, mixed> $images Source images
     * @return array<int, array<string, string>> Image input items
     */
    private function sources( array $images ) : array
    {
        $sources = [];

        foreach( $images as $image )
        {
            if( !( $image instanceof Image ) ) {
                throw new BadRequestException( 'Meta editing references must be Image objects' );
            }

            $sources[] = ['image_url' => $image->url() ?? 'data:' . $image->mimeType() . ';base64,' . $image->base64()];
        }

        return $sources;
    }
}
