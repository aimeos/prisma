<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\FalApi;
use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Contracts\Video\Extend;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Contracts\Video\Repaint;
use Aimeos\Prisma\Contracts\Video\Uncrop;
use Aimeos\Prisma\Contracts\Video\Upscale;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;


/**
 * Video models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/veo3.1". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 * Only describe() uses the OpenRouter video endpoint of fal synchronously and model() selects the LLM,
 * e.g. "google/gemini-2.5-pro".
 */
class Fal extends Base
    implements Cancel, Describe, Extend, Imagine, Repaint, Resume, Uncrop, Upscale
{
    use FalApi, GeneratesVideo;


    /** @var array<int, string> */
    private array $downloadHosts;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $hosts = $config['download_hosts'] ?? ['fal.media'];
        $this->downloadHosts = is_array( $hosts ) ? array_values( array_filter( $hosts, 'is_string' ) ) : ['fal.media'];

        $this->header( 'Authorization', 'Key ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://queue.fal.run' ) );
    }


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';

        return $this->falRouter( 'openrouter/router/video', $prompt, ['video_urls' => [$this->falMedia( $video )]], $options );
    }


    public function extend( Video $video, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/veo3.1/extend-video' ), array_replace( $options, [
            'video_url' => $this->falMedia( $video ),
            'prompt' => $prompt,
        ] ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $input = array_replace( $options, $this->settings( $options ), ['prompt' => $prompt] );
        $images = $this->images( $media['references'] ?? [], 7 );
        $start = $media['start'] ?? null;
        $end = $media['end'] ?? null;

        if( $start instanceof Image ) {
            $input[empty( $images ) ? 'image_url' : 'start_image_url'] = $this->falMedia( $start );
        }

        // end frames require a start frame
        if( $start instanceof Image && $end instanceof Image ) {
            $input['end_image_url'] = $this->falMedia( $end );
        }

        if( !empty( $images ) ) {
            return $this->falQueue( (string) $this->modelName( 'fal-ai/kling-video/o3/pro/reference-to-video' ), $input + ['image_urls' => $images] );
        }

        if( $start instanceof Image ) {
            return $this->falQueue( (string) $this->modelName( 'fal-ai/kling-video/o3/pro/image-to-video' ), $input );
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/kling-video/o3/pro/text-to-video' ), $input );
    }


    public function repaint( Video $video, string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        [$media, $options] = $this->repaintArguments( $media, $options );

        $input = ['video_url' => $this->falMedia( $video ), 'prompt' => $prompt];

        if( !empty( $images = $this->images( $media['references'] ?? [], 4 ) ) ) {
            $input['image_urls'] = $images;
        }

        // the edited video keeps its original sound only, no new audio can be generated
        if( is_bool( $options['audio'] ?? null ) ) {
            $input['keep_audio'] = $options['audio'];
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/kling-video/o3/pro/video-to-video/edit' ), array_replace( $options, $input ) );
    }


    public function uncrop( Video $video, string $prompt, float $top, float $right, float $bottom, float $left, array $options = [] ) : FileResponse
    {
        $edges = $this->uncropEdges( $top, $right, $bottom, $left );

        // the model expands all selected sides by the same ratio
        return $this->falQueue( (string) $this->modelName( 'fal-ai/wan-vace-14b/outpainting' ), array_replace( $options, [
            'video_url' => $this->falMedia( $video ),
            'prompt' => $prompt,
            'expand_top' => $edges['top'] > 0,
            'expand_right' => $edges['right'] > 0,
            'expand_bottom' => $edges['bottom'] > 0,
            'expand_left' => $edges['left'] > 0,
            'expand_ratio' => max( $edges ),
        ] ) );
    }


    public function upscale( Video $video, int $factor, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/seedvr/upscale/video' ), array_replace( $options, [
            'video_url' => $this->falMedia( $video ),
            'upscale_mode' => 'factor',
            'upscale_factor' => max( 1, min( 10, $factor ) ),
        ] ) );
    }


    /**
     * Builds a lazy video whose eventual download stays on the fal CDN.
     *
     * @param string $url Video URL returned by fal
     * @param string|null $mimeType Content type of the video if known
     * @return Video Video file
     */
    protected function falFile( string $url, ?string $mimeType ) : Video
    {
        $video = Video::fromUrl( $url, $mimeType );
        $video->restrictHosts( $this->downloadHosts );

        return $video;
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
        $images = array_filter( is_array( $references ) ? $references : [], fn( $file ) => $file instanceof Image );

        return array_map( $this->falMedia(...), array_slice( array_values( $images ), 0, $max ) );
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
