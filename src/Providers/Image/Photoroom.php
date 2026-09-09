<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Detext;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Relocate;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Photoroom extends Base implements Background, Detext, Imagine, Isolate, Relocate, Repaint, Upscale
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'x-api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $config['url'] ?? 'https://image-api.photoroom.com' );
    }


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['background.seed', 'background.expandPrompt.mode', 'shadow.mode', 'lighting.mode'] );

        return $this->request( [
            'removeBackground' => 'true', 'background.prompt' => $prompt,
        ] + $allowed, ['imageFile' => $image], $options );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        $mode = $options['textRemoval.mode'] ?? 'ai.all';

        if( !in_array( $mode, ['ai.all', 'ai.natural', 'ai.artificial'], true ) ) {
            throw new PrismaException( 'Unsupported Photoroom text removal mode' );
        }

        return $this->request( [
            'removeBackground' => 'false', 'textRemoval.mode' => $mode,
        ], ['imageFile' => $image], $options );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        if( $images ) {
            throw new PrismaException( 'Photoroom imagine does not support reference images; use repaint instead' );
        }

        $allowed = $this->allowed( $options, ['imageFromPrompt.seed', 'imageFromPrompt.size'] );

        return $this->request( [
            'removeBackground' => 'false', 'imageFromPrompt.prompt' => $prompt,
        ] + $allowed, [], $options );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['keepExistingAlphaChannel', 'segmentation.mode', 'segmentation.prompt', 'segmentation.negativePrompt'] );

        return $this->request( [
            'removeBackground' => 'true', 'background.color' => 'transparent',
        ] + $allowed, ['imageFile' => $image], $options );
    }


    public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['background.scaling', 'shadow.mode', 'lighting.mode'] );

        return $this->request( ['removeBackground' => 'true'] + $allowed, [
            'imageFile' => $image, 'background.imageFile' => $bgimage,
        ], $options );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['editWithAI.seed'] );

        return $this->request( [
            'removeBackground' => 'false', 'editWithAI.mode' => 'ai.auto', 'editWithAI.prompt' => $prompt,
        ] + $allowed, ['imageFile' => $image], $options );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        if( $factor !== 4 ) {
            throw new PrismaException( 'Photoroom only supports 4x upscaling' );
        }

        $mode = $options['upscale.mode'] ?? 'ai.fast';

        if( !in_array( $mode, ['ai.fast', 'ai.slow'], true ) ) {
            throw new PrismaException( 'Unsupported Photoroom upscale mode' );
        }

        // Geometry options would change the requested scaling factor.
        $allowed = $this->allowed( $options, ['export.format', 'export.dpi'] );

        return $this->request( [
            'removeBackground' => 'false', 'upscale.mode' => $mode,
        ], ['imageFile' => $image], $allowed );
    }


    /**
     * Submit an Image Editing API (Plus plan) request and return its binary image.
     *
     * @param array<string, mixed> $data Operation parameters
     * @param array<string, Image> $files Images to upload
     * @param array<string, mixed> $options Provider options
     */
    protected function request( array $data, array $files, array $options ) : FileResponse
    {
        $allowed = $this->allowed( $options, [
            'export.format', 'export.dpi', 'outputSize', 'padding', 'paddingTop', 'paddingRight',
            'paddingBottom', 'paddingLeft', 'horizontalAlignment', 'verticalAlignment', 'scaling',
        ] );
        $data += $allowed;

        if( $files ) {
            $data += ['referenceBox' => 'originalImage'];
        }
        $request = $this->payload( $data, $files );
        $response = $this->client()->post( 'v2/edit', ['multipart' => $request] );
        $this->validate( $response );

        return FileResponse::fromBinary( $response->getBody(), $response->getHeaderLine( 'Content-Type' ) );
    }
}
