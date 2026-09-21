<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Imagine;
use Aimeos\Prisma\Contracts\Image\Inpaint;
use Aimeos\Prisma\Contracts\Image\Repaint;
use Aimeos\Prisma\Contracts\Image\Uncrop;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\NotFoundException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Blackforestlabs extends Base implements Imagine, Inpaint, Repaint, Resume, Uncrop
{
    private const RESULT = '#/v\d+/get_result$#D';


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'x-key', $config['api_key'] );
        $this->header( 'Content-Type', 'application/json' );
        $this->baseUrl( $config['url'] ?? 'https://api.bfl.ai' );
    }


    public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
    {
        $model = (string) $this->modelName( 'flux-2-pro-preview' );
        $names = match( $model ) {
            'flux-2-pro', 'flux-2-pro-preview' => ['seed', 'width', 'height', 'safety_tolerance', 'output_format', 'webhook_url', 'webhook_secret'],
            'flux-2-flex' => ['prompt_upsampling', 'input_image_blob_path', 'seed', 'width', 'height', 'guidance', 'steps', 'safety_tolerance', 'output_format', 'webhook_url', 'webhook_secret'],
            'flux-kontext-pro', 'flux-kontext-max' => ['seed', 'aspect_ratio', 'output_format', 'webhook_url', 'webhook_secret', 'prompt_upsampling', 'safety_tolerance'],
            'flux-pro-1.1-ultra' => ['prompt_upsampling', 'seed', 'aspect_ratio', 'safety_tolerance', 'output_format', 'raw', 'image_prompt', 'image_prompt_strength', 'webhook_url', 'webhook_secret'],
            'flux-pro-1.1' => ['image_prompt', 'width', 'height', 'prompt_upsampling', 'seed', 'safety_tolerance', 'output_format', 'webhook_url', 'webhook_secret'],
            'flux-dev' => ['image_prompt', 'width', 'height', 'steps', 'prompt_upsampling', 'seed', 'guidance', 'safety_tolerance', 'output_format', 'webhook_url', 'webhook_secret'],
            default => array_keys( $options )
        };

        $allowed = $this->allowed( $options, $names );
        $files = [];

        if( $file = array_shift( $images ) )
        {
            if( str_starts_with( $model, 'flux-pro' ) || str_starts_with( $model, 'flux-dev' ) ){
                $files['input_prompt'] = $file->base64();
            } else {
                $files['input_image'] = $file->base64();

                foreach( $images as $index => $file ) {
                    $files['input_image_' . ( $index + 2 )] = $file->base64();
                }
            }
        }

        $request = ['prompt' => $prompt] + $allowed + ['output_format' => 'png'] + $files;
        $response = $this->client()->post( 'v1/' . $model, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'flux-pro-1.0-fill' );
        $allowed = $this->allowed( $options, [
            'steps', 'prompt_upsampling', 'seed', 'guidance', 'output_format',
            'safety_tolerance', 'webhook_url', 'webhook_secret'
        ] ) + ['output_format' => 'png'];

        $request = ['image' => $image->base64(), 'mask' => $mask->base64(), 'prompt' => $prompt] + $allowed;
        $response = $this->client()->post( 'v1/' . $model, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    /**
     * Repaints an image using the selected image-editing model.
     *
     * @param Image $image Source image
     * @param string $prompt Description of the requested changes
     * @param array<string, mixed> $options Model-specific generation options
     * @return FileResponse Asynchronous edited image response
     */
    public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
    {
        return $this->imagine( $prompt, [$image], $options );
    }


    /**
     * Resumes polling a job.
     *
     * @param string $jobId Polling URL of the job returned by jobId()
     * @return FileResponse Asynchronous image response
     * @throws BadRequestException If the URL isn't a result URL of Black Forest Labs
     */
    public function resume( string $jobId ) : FileResponse
    {
        return FileResponse::fromAsync( $this->poll( $jobId ), 2, jobId: $jobId );
    }


    public function uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
    {
        $model = $this->modelName( 'flux-pro-1.0-expand' );
        $data = [
            'image' => $image->base64(),
            'top' => min( $top, 2048 ),
            'right' => min( $right, 2048 ),
            'bottom' => min( $bottom, 2048 ),
            'left' => min( $left, 2048 ),
        ];
        $allowed = $this->allowed( $options, [
            'guidance', 'output_format', 'prompt', 'prompt_upsampling', 'safety_tolerance',
            'seed', 'steps', 'webhook_url', 'webhook_secret'
        ] );

        $request = $data + $allowed + ['output_format' => 'png'];
        $response = $this->client()->post( 'v1/' . $model, ['json' => $request] );

        return $this->toFileResponse( $response );
    }


    protected function poll( string $url ) : \Closure
    {
        // Never send the API key to hosts outside of Black Forest Labs, e.g. regional ones like api.eu1.bfl.ai,
        // or to other endpoints than the result endpoint
        $uri = $this->jobUrl( $url, self::RESULT, '#^api(\.[a-z0-9-]+)?\.bfl\.ai$#D', 'Invalid Black Forest Labs polling URL' );

        return function( FileResponse $fr ) use ( $uri ) : bool {

            $response = $this->client()->get( $uri, ['allow_redirects' => false] );
            $this->validate( $response );

            $data = $this->fromJson( $response );
            $fr->withMeta( $data );
            $status = $data['status'] ?? null;

            // the final result contains the settled cost, which is also available for resumed jobs
            if( is_numeric( $data['cost'] ?? null ) ) {
                $fr->withUsage( (float) $data['cost'] );
            }

            // results are kept for ten minutes only, but an unknown ID isn't reported as failed job
            if( $status === 'Task not found' ) {
                throw new NotFoundException( 'Black Forest Labs job not found or expired' );
            }

            if( in_array( $status, ['Error', 'Content Moderated', 'Request Moderated'], true ) ) {
                throw new FailedException( 'Black Forest Labs job failed: ' . $status );
            }

            if( $status !== 'Ready' ) {
                return false;
            }

            $sample = $data['result']['sample'] ?? $data['sample'] ?? null;

            if( !is_string( $sample ) || $sample === '' ) {
                throw new FailedException( 'No image data found in response' );
            }

            $fr->add( Image::fromUrl( $sample ) );

            return true;
        };
    }


    protected function toFileResponse( ResponseInterface $response ) : FileResponse
    {
        $this->validate( $response );
        $data = $this->fromJson( $response );

        if( !is_string( $data['polling_url'] ?? null ) || $data['polling_url'] === '' ) {
            throw new FailedException( 'No polling URL in response' );
        }

        $cost = $data['cost'] ?? 0;

        return $this->resume( $data['polling_url'] )
            ->withUsage( is_numeric( $cost ) ? (float) $cost : 0 );
    }
}
