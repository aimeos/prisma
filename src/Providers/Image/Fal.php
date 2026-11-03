<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Contracts\Image\Detext;
use Aimeos\Prisma\Contracts\Image\Erase;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Recognize;
use Aimeos\Prisma\Contracts\Image\Relocate;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Fal as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


/**
 * Image models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/nano-banana-pro". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 * Only describe() and recognize() use the OpenRouter vision endpoint of fal synchronously and model()
 * selects the LLM, e.g. "anthropic/claude-sonnet-5".
 */
class Fal extends Base
    implements Background, Describe, Detext, Erase, Imagine, Inpaint, Isolate, Recognize, Relocate, Repaint, Uncrop, Upscale
{
    protected const FILE = Image::class;
    protected const MAX_BYTES = 25 * 1024 * 1024;


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/bria/background/replace' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'prompt' => $prompt,
        ] ) );
    }


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->summarize( 'openrouter/router/vision', $lang, ['image_urls' => [$this->media( $image )]], $options );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/image-editing/text-removal' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
        ] ) );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/bria/eraser' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'mask_url' => $this->media( $mask ),
        ] ) );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        if( empty( $images ) ) {
            return $this->queue( (string) $this->modelName( 'fal-ai/flux-2' ), array_replace( $options, ['prompt' => $prompt] ) );
        }

        return $this->queue( (string) $this->modelName( 'fal-ai/flux-2/edit' ), array_replace( $options, [
            'prompt' => $prompt,
            'image_urls' => array_values( array_map( $this->media(...), $images ) ),
        ] ) );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/flux-pro/v1/fill' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'mask_url' => $this->media( $mask ),
            'prompt' => $prompt,
        ] ) );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/birefnet/v2' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
        ] ) );
    }


    public function recognize( Image $image, array $options = [] ) : TextResponse
    {
        $prompt = 'Extract all visible text from the image. Return only the text in reading order.';

        return $this->router( 'openrouter/router/vision', $prompt, ['image_urls' => [$this->media( $image )]], $options );
    }


    public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/bria/background/replace' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'ref_image_url' => $this->media( $bgimage ),
        ] ) );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/flux-pro/kontext' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'prompt' => $prompt,
        ] ) );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        if( ( $size = @getimagesizefromstring( (string) $image->binary() ) ) === false ) {
            throw new BadRequestException( 'Unable to get image size' );
        }

        [$width, $height] = $size;

        return $this->queue( (string) $this->modelName( 'fal-ai/bria/expand' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'canvas_size' => [$width + $left + $right, $height + $top + $bottom],
            'original_image_size' => [$width, $height],
            'original_image_location' => [$left, $top],
        ] ) );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        return $this->queue( (string) $this->modelName( 'fal-ai/seedvr/upscale/image' ), array_replace( $options, [
            'image_url' => $this->media( $image ),
            'upscale_mode' => 'factor',
            'upscale_factor' => max( 1, min( 10, $factor ) ),
        ] ) );
    }

}
