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
 * describe() and recognize() use the OpenRouter vision endpoint of fal, can't be resumed and the "model"
 * option selects the LLM, e.g. "anthropic/claude-sonnet-5" (default: "google/gemini-3.8-flash").
 */
class Fal extends Base
    implements Background, Describe, Detext, Erase, Imagine, Inpaint, Isolate, Recognize, Relocate, Repaint, Uncrop, Upscale
{
    protected const FILE = Image::class;
    protected const MAX_BYTES = 25 * 1024 * 1024;


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/bria/background/replace', [
            'image_url' => $this->uri( $image ),
            'prompt' => $prompt,
        ], $options );
    }


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->router( 'openrouter/router/vision', $this->summary( $lang ), ['image_urls' => [$this->uri( $image )]], $options );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/image-editing/text-removal', [
            'image_url' => $this->uri( $image ),
        ], $options );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/bria/eraser', [
            'image_url' => $this->uri( $image ),
            'mask_url' => $this->uri( $mask ),
        ], $options );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        if( empty( $images ) ) {
            return $this->submit( 'fal-ai/flux-2', ['prompt' => $prompt], $options );
        }

        return $this->submit( 'fal-ai/flux-2/edit', [
            'prompt' => $prompt,
            'image_urls' => array_values( array_map( $this->uri(...), $images ) ),
        ], $options );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/flux-pro/v1/fill', [
            'image_url' => $this->uri( $image ),
            'mask_url' => $this->uri( $mask ),
            'prompt' => $prompt,
        ], $options );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/birefnet/v2', [
            'image_url' => $this->uri( $image ),
        ], $options );
    }


    public function recognize( Image $image, array $options = [] ) : TextResponse
    {
        return $this->router( 'openrouter/router/vision', $this->extraction(), ['image_urls' => [$this->uri( $image )]], $options );
    }


    public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/bria/background/replace', [
            'image_url' => $this->uri( $image ),
            'ref_image_url' => $this->uri( $bgimage ),
        ], $options );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/flux-pro/kontext', [
            'image_url' => $this->uri( $image ),
            'prompt' => $prompt,
        ], $options );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        [$width, $height] = $image->size();

        return $this->submit( 'fal-ai/bria/expand', [
            'image_url' => $this->uri( $image ),
            'canvas_size' => [$width + $left + $right, $height + $top + $bottom],
            'original_image_size' => [$width, $height],
            'original_image_location' => [$left, $top],
        ], $options );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/seedvr/upscale/image', [
            'image_url' => $this->uri( $image ),
        ] + $this->upscaling( $factor ), $options );
    }
}
