<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Concerns\FalApi;
use Aimeos\Prisma\Contracts\Cancel;
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
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
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
    implements Background, Cancel, Describe, Detext, Erase, Imagine, Inpaint, Isolate, Recognize, Relocate, Repaint, Resume, Uncrop, Upscale
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


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $prompt = 'Summarize the content of the file in a few words in plain text format in the language of ISO code "'
            . ( $lang ?? 'en' ) . '".';

        return $this->vision( $image, $prompt, $options );
    }


    public function detext( Image $image, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/image-editing/text-removal' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
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


    public function recognize( Image $image, array $options = [] ) : TextResponse
    {
        return $this->vision( $image, 'Extract all visible text from the image. Return only the text in reading order.', $options );
    }


    public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
    {
        return $this->falQueue( (string) $this->modelName( 'fal-ai/bria/background/replace' ), array_replace( $options, [
            'image_url' => $this->falMedia( $image ),
            'ref_image_url' => $this->falMedia( $bgimage ),
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
     * Builds a lazy image whose eventual download stays on the fal CDN.
     *
     * @param string $url Image URL returned by fal
     * @param string|null $mimeType Content type of the image if known
     * @return Image Image file
     */
    protected function falFile( string $url, ?string $mimeType ) : Image
    {
        $image = Image::fromUrl( $url, $mimeType );
        $image->restrictHosts( $this->downloadHosts );
        $image->maxSize( self::MAX_DOWNLOAD_BYTES );

        return $image;
    }


    /**
     * Asks a vision LLM about the image using the synchronous OpenRouter endpoint of fal.
     *
     * @param Image $image Input image
     * @param string $prompt Instructions for the LLM
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Response text
     */
    protected function vision( Image $image, string $prompt, array $options ) : TextResponse
    {
        $response = $this->client()->post( 'https://fal.run/openrouter/router/vision', ['json' => array_replace( $options, [
            'model' => $this->modelName( 'google/gemini-3.8-flash' ),
            'prompt' => $prompt,
            'image_urls' => [$this->falMedia( $image )],
        ] )] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $usage = is_array( $data['usage'] ?? null ) ? $data['usage'] : [];
        $used = $usage['total_tokens'] ?? null;

        if( !is_string( $data['output'] ?? null ) ) {
            throw new PrismaException( 'No text found in fal response' );
        }

        return TextResponse::fromText( $data['output'] )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
            ->withMeta( array_diff_key( $data, ['output' => 1, 'usage' => 1] ) );
    }
}
