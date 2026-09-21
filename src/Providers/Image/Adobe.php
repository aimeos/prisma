<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Cancel;
use Aimeos\Prisma\Contracts\Image\Background;
use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Relocate;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Contracts\Image\Upscale;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;


class Adobe extends Base implements Background, Cancel, Imagine, Inpaint, Relocate, Repaint, Resume, Uncrop, Upscale
{
    // Status path whose job ID can't be a dot segment or contain encoded characters
    private const HOSTS = '#^firefly-[a-z0-9-]+\.adobe\.io$#D';
    private const STATUS = '#(/v\d+)/status/(\w[\w:.-]*)$#D';


    /**
     * @param array<string, mixed> $config api_key (IMS access token), client_id (Adobe API key), and optional url
     */
    public function __construct( array $config )
    {
        if( !$this->config( $config, 'api_key' ) || !$this->config( $config, 'client_id' ) ) {
            throw new PrismaException( 'Adobe requires an API access token and client_id' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->header( 'x-api-key', $this->config( $config, 'client_id' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://firefly-api.adobe.io' ) );
    }


    /**
     * Generates a background and composites the subject into it.
     *
     * @param Image $image Subject image
     * @param string $prompt Background description
     * @param array<string, mixed> $options Firefly composite options; optional mask Image
     * @return FileResponse Asynchronous composite images
     */
    public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        $mask = $this->maskOption( $options, 'mask' );

        if( $mask && isset( $options['placement'] ) ) {
            throw new BadRequestException( 'Adobe background mask cannot be combined with placement' );
        }

        $data = ['prompt' => $prompt, 'image' => $this->imageReference( $image )];

        if( $mask ) {
            $data['mask'] = $this->imageReference( $mask );
        }

        return $this->request( 'v3/images/generate-object-composite-async', $data + $this->allowed( $options, [
            'contentClass', 'numVariations', 'placement', 'seeds', 'size', 'style'
        ] ) );
    }


    /**
     * Cancels a pending or running Firefly job.
     *
     * @param string $jobId Status URL of the job returned by jobId()
     * @return void
     * @throws BadRequestException If the URL isn't a status URL of Adobe or the configured API host
     */
    public function cancel( string $jobId ) : void
    {
        // Never send Adobe credentials to an unrelated host or endpoint
        $uri = $this->jobUrl( $jobId, self::STATUS, self::HOSTS, 'Invalid Adobe status URL' );
        $path = (string) preg_replace( self::STATUS, '$1/cancel/$2', $uri->getPath(), 1 );

        $response = $this->client()->put( $uri->withPath( $path )->withQuery( '' ), ['allow_redirects' => false] );

        $this->validate( $response );
    }


    /**
     * Generates images using Image5 by default, or an explicitly selected Image3/4 model.
     *
     * @param string $prompt Image description or editing instruction
     * @param array<int, Image> $images At most one reference; Image5 editing input or Image3/4 style reference
     * @param array<string, mixed> $options Options for the selected Firefly generation model
     * @return FileResponse Asynchronous generated images
     */
    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $image = $images ? reset( $images ) : null;

        if( count( $images ) > 1 || ( $images && !( $image instanceof Image ) ) ) {
            throw new BadRequestException( 'Adobe accepts at most one reference Image' );
        }

        $model = (string) $this->modelName( 'image5' );
        $data = ['prompt' => $prompt];

        if( $model === 'image5' )
        {
            if( $image && isset( $options['aspectRatio'] ) && $options['aspectRatio'] !== 'auto' ) {
                throw new BadRequestException( 'Image5 references require aspectRatio auto or omitted' );
            }

            $allowed = $this->allowed( $options, ['aspectRatio', 'resolutionLevel', 'modelSpecificPayload', 'numVariations', 'seeds'] );
            $data['modelId'] = 'firefly_image';

            if( $image ) {
                $data['referenceBlobs'] = [$this->imageReference( $image ) + ['usage' => 'general']];
            }

            return $this->request( 'v4/images/generate-async', $data + $allowed, $model );
        }

        $allowed = $this->allowed( $options, [
            'contentClass', 'customModelId', 'negativePrompt', 'numVariations', 'promptBiasingLocaleCode',
            'seeds', 'size', 'structure', 'style', 'upsamplerType', 'visualIntensity'
        ] );

        if( $image && !is_array( $allowed['style'] ?? [] ) ) {
            throw new BadRequestException( 'Adobe style options must be an array' );
        }

        if( $image ) {
            $allowed['style']['imageReference'] = $this->imageReference( $image );
        }

        return $this->request( 'v3/images/generate-async', $data + $allowed, $model );
    }


    /**
     * Fills the selected image region.
     *
     * @param Image $image Source image
     * @param Image $mask Mask selecting the region to fill
     * @param string $prompt Description of the replacement content
     * @param array<string, mixed> $options Firefly fill options; invert optionally reverses the mask
     * @return FileResponse Asynchronous filled images
     */
    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->request( 'v3/images/fill-async', [
            'prompt' => $prompt,
            'image' => $this->imageReference( $image ),
            'mask' => $this->imageReference( $mask ) + $this->allowed( $options, ['invert'] ),
        ] + $this->allowed( $options, ['negativePrompt', 'numVariations', 'promptBiasingLocaleCode', 'seeds', 'size'] ) );
    }


    /**
     * Places a subject on an existing background.
     *
     * @param Image $image Subject image
     * @param Image $bgimage Background image
     * @param array<string, mixed> $options Required fillAreaMask Image; mode precise (default) or adaptive, optional adaptive mask Image
     * @return FileResponse Asynchronous composite images
     */
    public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
    {
        $mode = $options['mode'] ?? 'precise';
        $area = $this->maskOption( $options, 'fillAreaMask' );
        $mask = $this->maskOption( $options, 'mask' );

        if( !in_array( $mode, ['precise', 'adaptive'], true ) || !$area || ( $mask && $mode !== 'adaptive' ) ) {
            throw new BadRequestException( 'Relocate requires fillAreaMask and mode precise or adaptive; mask requires adaptive mode' );
        }

        $data = [
            'background' => ['image' => $this->imageReference( $bgimage ), 'fillAreaMask' => $this->imageReference( $area )],
            'object' => ['image' => $this->imageReference( $image )],
        ];

        if( $mask ) {
            $data['object']['mask'] = $this->imageReference( $mask );
        }

        $names = $mode === 'precise' ? ['blend'] : ['harmonization', 'shadowIntensity', 'preserveBackground'];
        return $this->request( 'v3/images/' . $mode . '-composite', $data + $this->allowed( $options, array_merge( $names, ['numVariations', 'seeds', 'output'] ) ) );
    }


    /**
     * Edits an image using Image5 instructions.
     *
     * @param Image $image Source image
     * @param string $prompt Editing instruction
     * @param array<string, mixed> $options Image5 generation options
     * @return FileResponse Asynchronous edited image
     */
    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        if( $this->modelName( 'image5' ) !== 'image5' ) {
            throw new BadRequestException( 'Adobe repaint requires image5' );
        }

        return $this->imagine( $prompt, [$image], $options );
    }


    /**
     * Resumes polling an asynchronous Firefly job.
     *
     * @param string $jobId Status URL of the job returned by jobId()
     * @return FileResponse Asynchronous image response
     * @throws BadRequestException If the URL isn't a status URL of Adobe or the configured API host
     */
    public function resume( string $jobId ) : FileResponse
    {
        return FileResponse::fromAsync( $this->poll( $jobId ), 2, jobId: $jobId );
    }


    /**
     * Expands the canvas by the requested pixel margins without resizing the source.
     *
     * @param Image $image Source image with readable raster dimensions
     * @param int $top Pixels to add above the source
     * @param int $right Pixels to add to the right
     * @param int $bottom Pixels to add below the source
     * @param int $left Pixels to add to the left
     * @param array<string, mixed> $options Firefly expansion options (prompt, seeds, numVariations)
     * @return FileResponse Asynchronous expanded images
     */
    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        if( min( $top, $right, $bottom, $left ) < 0 || max( $top, $right, $bottom, $left ) > 3999 ) {
            throw new BadRequestException( 'Adobe expansion margins must be between 0 and 3999 pixels' );
        }

        $size = @getimagesizefromstring( $image->binary() ?? '' );

        if( !$size || $size[0] + $left + $right > 3999 || $size[1] + $top + $bottom > 3999 ) {
            throw new BadRequestException( 'Adobe expansion requires a readable image and an output no larger than 3999x3999' );
        }

        return $this->request( 'v3/images/expand-async', [
            'image' => $this->imageReference( $image ),
            'size' => ['width' => $size[0] + $left + $right, 'height' => $size[1] + $top + $bottom],
            'placement' => ['inset' => compact( 'top', 'right', 'bottom', 'left' )],
        ] + $this->allowed( $options, ['prompt', 'seeds', 'numVariations'] ) );
    }


    /**
     * Upscales an image using the precise upsampler.
     *
     * @param Image $image Source image
     * @param int $factor Scale factor: 2, 3, 4, or 6
     * @param array<string, mixed> $options seeds (one to four integers), default [0]
     * @return FileResponse Asynchronous upscaled images
     */
    public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
    {
        if( !in_array( $factor, [2, 3, 4, 6], true ) ) {
            throw new BadRequestException( 'Adobe upscale factor must be 2, 3, 4, or 6' );
        }

        return $this->request( 'v3/images/upscale', [
            'image' => $this->imageReference( $image ), 'upscaleFactor' => $factor,
        ] + $this->allowed( $options, ['seeds'] ) + ['seeds' => [0]], 'precise_upsampler_v1' );
    }


    /**
     * @param array<string, mixed> $options Provider options
     * @param string $name Mask option name
     * @return Image|null Validated optional mask
     */
    protected function maskOption( array $options, string $name ) : ?Image
    {
        if( isset( $options[$name] ) && !( $options[$name] instanceof Image ) ) {
            throw new BadRequestException( $name . ' must be an Image' );
        }

        return $options[$name] ?? null;
    }


    /**
     * @param Image $image Input image; URLs must use Adobe-supported storage domains
     * @return array{source: array<string, string>} Image reference with a URL or upload ID for local/binary images
     */
    protected function imageReference( Image $image ) : array
    {
        if( $url = $image->url() ) {
            return ['source' => ['url' => $url]];
        }

        $response = $this->client()->post( 'v2/storage/image', [
            'headers' => ['Content-Type' => $image->mimeType()], 'body' => $image->binary(), 'allow_redirects' => false
        ] );
        $this->validate( $response );
        $id = $this->fromJson( $response )['images'][0]['id'] ?? null;

        if( !is_string( $id ) || $id === '' ) {
            throw new PrismaException( 'No upload ID in Adobe response' );
        }

        return ['source' => ['uploadId' => $id]];
    }


    /**
     * Returns a closure that polls a Firefly job.
     *
     * @param string $url Status URL of the job
     * @param array<string, mixed> $job Fields of the submit response the status response doesn't repeat
     * @return \Closure Polling closure populating the file response
     * @throws BadRequestException If the URL isn't a status URL of Adobe or the configured API host
     */
    protected function poll( string $url, array $job = [] ) : \Closure
    {
        // Never send Adobe credentials to an unrelated polling host or endpoint
        $uri = $this->jobUrl( $url, self::STATUS, self::HOSTS, 'Invalid Adobe status URL' );

        return function( FileResponse $result ) use ( $uri, $job ) : bool {
            $response = $this->client()->get( $uri, ['allow_redirects' => false] );
            $this->validate( $response );
            $data = $this->fromJson( $response );

            // Adobe doesn't repeat the job fields of the submit response in the status response
            $result->withMeta( $data + $job );

            // a job whose cancellation is pending may still succeed
            if( in_array( $data['status'] ?? null, ['pending', 'running', 'cancel_pending'], true ) ) {
                return false;
            }

            if( in_array( $data['status'] ?? null, ['failed', 'canceled', 'cancelled'], true ) ) {
                throw new FailedException( 'Adobe image job failed or was canceled' );
            }

            if( ( $data['status'] ?? null ) !== 'succeeded' ) {
                throw new PrismaException( 'Adobe image job returned an invalid status' );
            }

            $outputs = $data['result']['outputs'] ?? null;

            if( !is_array( $outputs ) || !$outputs ) {
                throw new FailedException( 'No images in Adobe response' );
            }

            $files = [];

            foreach( $outputs as $output )
            {
                $image = $output['image']['url'] ?? null;

                if( !is_string( $image ) || $image === '' ) {
                    throw new FailedException( 'Invalid image in Adobe response' );
                }

                $files[] = Image::fromUrl( $image );
            }

            foreach( $files as $file ) {
                $result->add( $file );
            }

            $result->withDescription( is_string( $data['result']['altText'] ?? null ) ? $data['result']['altText'] : null );
            return true;
        };
    }


    /**
     * @param string $endpoint Relative Firefly API endpoint
     * @param array<string, mixed> $data Request parameters
     * @param string|null $model Optional x-model-version header
     * @return FileResponse Asynchronous image response
     */
    protected function request( string $endpoint, array $data, ?string $model = null ) : FileResponse
    {
        $response = $this->client()->post( $endpoint, ['json' => $data, 'headers' => $model ? ['x-model-version' => $model] : [], 'allow_redirects' => false] );

        $this->validate( $response );

        $job = $this->fromJson( $response );
        $url = $job['statusUrl'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            throw new FailedException( 'No status URL in response' );
        }

        return FileResponse::fromAsync( $this->poll( $url, $job ), 2, jobId: $url )
            ->withMeta( $job )->withRateLimit( $this->getRateLimit( $response ) );
    }
}
