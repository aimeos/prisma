<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Extend;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Contracts\Video\Uncrop;
use Aimeos\Prisma\Contracts\Video\Upscale;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Fal as Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


/**
 * Video models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/veo3.1". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 * Only describe() uses the OpenRouter video endpoint of fal synchronously and the "model" option selects
 * the LLM (default: "google/gemini-3.8-flash").
 */
class Fal extends Base
    implements Describe, Extend, Imagine, Repaint, Uncrop, Upscale
{
    use GeneratesVideo;


    protected const FILE = Video::class;
    protected const MAX_BYTES = 512 * 1024 * 1024;

    /** Maximum number of reference images of the Kling generation and edit endpoints */
    private const MAX_REFERENCES = 7;
    private const MAX_EDIT_REFERENCES = 4;


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        return $this->router( 'openrouter/router/video', $this->summary( $lang ), ['video_urls' => [$video->uri()]], $options );
    }


    public function extend( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/veo3.1/extend-video', [
            'video_url' => $video->uri(),
            'prompt' => $prompt,
        ], $options );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $input = array_replace( $this->settings( $options ), ['prompt' => $prompt] );
        $images = $this->images( $media['references'] ?? [], self::MAX_REFERENCES );
        $start = $this->image( $media['start'] ?? null );
        $end = $this->image( $media['end'] ?? null );

        if( $start ) {
            $input[empty( $images ) ? 'image_url' : 'start_image_url'] = $start->uri();
        }

        // end frames require a start frame
        if( $start && $end ) {
            $input['end_image_url'] = $end->uri();
        }

        if( !empty( $images ) ) {
            $input['image_urls'] = $images;
            return $this->submit( 'fal-ai/kling-video/o3/pro/reference-to-video', $input, $options );
        }

        if( $start ) {
            return $this->submit( 'fal-ai/kling-video/o3/pro/image-to-video', $input, $options );
        }

        return $this->submit( 'fal-ai/kling-video/o3/pro/text-to-video', $input, $options );
    }


    public function repaint( Video $video, string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        [$media, $options] = $this->repaintArguments( $media, $options );

        $input = ['video_url' => $video->uri(), 'prompt' => $prompt];

        if( !empty( $images = $this->images( $media['references'] ?? [], self::MAX_EDIT_REFERENCES ) ) ) {
            $input['image_urls'] = $images;
        }

        // the edited video keeps its original sound only, no new audio can be generated
        if( is_bool( $options['audio'] ?? null ) ) {
            $input['keep_audio'] = $options['audio'];
        }

        return $this->submit( 'fal-ai/kling-video/o3/pro/video-to-video/edit', $input, $options );
    }


    public function uncrop( Video $video, string $prompt, float $top, float $right, float $bottom, float $left, array $options = [] ) : FileResponse
    {
        $edges = $this->uncropEdges( $top, $right, $bottom, $left );

        // the model expands all selected sides by the same ratio
        return $this->submit( 'fal-ai/wan-vace-14b/outpainting', [
            'video_url' => $video->uri(),
            'prompt' => $prompt,
            'expand_top' => $edges['top'] > 0,
            'expand_right' => $edges['right'] > 0,
            'expand_bottom' => $edges['bottom'] > 0,
            'expand_left' => $edges['left'] > 0,
            'expand_ratio' => max( $edges ),
        ], $options );
    }


    public function upscale( Video $video, int $factor, array $options = [] ) : FileResponse
    {
        return $this->submit( 'fal-ai/seedvr/upscale/video', [
            'video_url' => $video->uri(),
        ] + $this->upscaling( $factor ), $options );
    }


    /**
     * Returns the value if it is an image.
     *
     * @param mixed $file Media file
     * @return Image|null Image or NULL if the value isn't an image
     */
    protected function image( mixed $file ) : ?Image
    {
        return $file instanceof Image ? $file : null;
    }


    /**
     * Returns the URLs of the reference images.
     *
     * @param mixed $references Reference media files
     * @param int $max Maximum number of reference images
     * @return array<int, string> Image URLs or data URIs
     */
    protected function images( mixed $references, int $max ) : array
    {
        $images = array_filter( array_map( $this->image(...), is_array( $references ) ? $references : [] ) );

        return array_map( fn( Image $image ) => $image->uri(), array_slice( array_values( $images ), 0, $max ) );
    }


    /**
     * Maps the common generation options to the fal parameters.
     *
     * @param array<string, mixed> $options Common and provider-specific options
     * @return array<string, mixed> Mapped parameters
     */
    protected function settings( array $options ) : array
    {
        $settings = [];

        if( is_int( $options['duration'] ?? null ) ) {
            $settings['duration'] = (string) $options['duration'];
        }

        if( is_string( $options['aspectRatio'] ?? null ) ) {
            $settings['aspect_ratio'] = $options['aspectRatio'];
        }

        if( is_bool( $options['audio'] ?? null ) ) {
            $settings['generate_audio'] = $options['audio'];
        }

        return $settings;
    }
}
