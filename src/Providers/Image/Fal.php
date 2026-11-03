<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\FalApi;
use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Erase;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Isolate;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


/**
 * Image models hosted by fal.ai.
 *
 * Each method uses a default model endpoint which can be replaced by any compatible fal endpoint
 * using model(), e.g. "fal-ai/nano-banana-pro". The common fields are mapped and all other options
 * are passed to the endpoint unchanged, so model specific parameters can be used as documented by fal.
 */
class Fal extends Base
    implements Background, Cancel, Erase, Imagine, Inpaint, Isolate, Repaint, Resume, Uncrop, Upscale
{
    use FalApi;


    private const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

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


    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/bria/background/replace' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'prompt' => $prompt,
        ] ) );
    }


    public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/bria/eraser' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'mask_url' => $this->falMedia( $mask ),
        ] ) );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        if( empty( $images ) ) {
            return $this->falQueue( (string) $this->modelName( 'fal-ai/flux-2' ), array_replace( $options, ['prompt' => $prompt] ) );
        }

        return $this->falQueue( (string) $this->modelName( 'fal-ai/flux-2/edit' ), array_replace( $options, [
            'prompt' => $prompt,
            'image_urls' => array_values( array_map( $this->falMedia(...), $images ) ),
        ] ) );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/flux-pro/v1/fill' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'mask_url' => $this->falMedia( $mask ),
            'prompt' => $prompt,
        ] ) );
    }


    public function isolate( Image $image, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/birefnet/v2' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
        ] ) );
    }


    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/flux-pro/kontext' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'prompt' => $prompt,
        ] ) );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        if( ( $size = @getimagesizefromstring( (string) $image->binary() ) ) === false ) {
            throw new BadRequestException( 'Unable to get image size' );
        }

        [$width, $height] = $size;

        return $this->falQueue( (string) $this->modelName( 'fal-ai/bria/expand' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'canvas_size' => [$width + $left + $right, $height + $top + $bottom],
            'original_image_size' => [$width, $height],
            'original_image_location' => [$left, $top],
        ] ) );
    }


    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/seedvr/upscale/image' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'upscale_mode' => 'factor',
            'upscale_factor' => max( 1, min( 10, $factor ) ),
        ] ) );
    }


    /**
     * Builds the output images from the result of a completed request.
     *
     * fal image endpoints return either a list of images or a single image object.
     *
     * @param array<string, mixed> $data Result data of the model endpoint
     * @return array<int, Image> Image files
     */
    protected function falFiles( array $data ) : array
    {
        $list = is_array( $data['images'] ?? null ) ? $data['images'] : [$data['image'] ?? null];
        $files = [];

        foreach( $list as $item )
        {
            if( is_array( $item ) && is_string( $item['url'] ?? null ) && $item['url'] !== '' ) {
                $files[] = $this->image( $item['url'], is_string( $item['content_type'] ?? null ) ? $item['content_type'] : null );
            }
        }

        return $files;
    }


    /**
     * Builds a lazy image whose eventual download stays on the fal CDN.
     *
     * @param string $url Image URL returned by fal
     * @param string|null $mimeType Content type of the image if known
     * @return Image Image file
     */
    private function image( string $url, ?string $mimeType ) : Image
    {
        $image = Image::fromUrl( $url, $mimeType );
        $image->restrictHosts( $this->downloadHosts );
        $image->maxSize( self::MAX_DOWNLOAD_BYTES );

        return $image;
    }
}
