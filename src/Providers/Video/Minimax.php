<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Concerns\GeneratesVideo;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Contracts\Video\Imagine;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Psr\Http\Message\ResponseInterface;


class Minimax extends Base implements Imagine, Resume
{
    use GeneratesVideo;

    /** Error codes of MiniMax responses with HTTP status 200 mapped to the HTTP status of their exception */
    private const ERRORS = [
        1001 => 504, // request timeout
        1002 => 429, 1039 => 429, 1041 => 429, 2045 => 429, 2056 => 429, // rate, token, connection and usage limits
        1004 => 401, 2049 => 401, // invalid API key
        1008 => 402, // insufficient balance
        1026 => 400, 1027 => 400, 1042 => 400, 2013 => 400, // sensitive content, invalid characters and parameters
    ];


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.minimax.io' ) );
    }


    public function imagine( string $prompt, array $media = [], array $options = [] ) : FileResponse
    {
        $response = $this->client()->post( 'v1/video_generation', [
            'json' => $this->request( $prompt, $media, $options ),
        ] );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $id = $data['task_id'] ?? null;

        $this->status( $data, $response );

        if( !is_string( $id ) || $id === '' ) {
            $this->videoFailed();
        }

        return $this->resume( $id );
    }


    public function resume( string $jobId ) : FileResponse
    {
        $jobId = $this->jobId( $jobId );

        return FileResponse::fromAsync( $this->poll( $jobId ), 5, jobId: $jobId );
    }


    /**
     * Returns a closure that polls a MiniMax generation task.
     *
     * @param string $id Task identifier
     * @return \Closure Polling closure that populates the file response
     */
    protected function poll( string $id ) : \Closure
    {
        return function( FileResponse $result ) use ( $id ) : bool {
            $response = $this->client()->get( 'v1/query/video_generation?task_id=' . rawurlencode( $id ) );
            $this->validate( $response );

            /** @var array<string, mixed> $data */
            $data = $this->fromJson( $response );
            $result->withMeta( $data );
            $status = $data['status'] ?? null;
            $code = is_numeric( $data['base_resp']['status_code'] ?? null ) ? (int) $data['base_resp']['status_code'] : 0;

            // the task query reports sensitive input (1026) or output (1027) as error of the task itself
            if( $status === 'Fail' || in_array( $code, [1026, 1027], true ) ) {
                // the status message of code 0 is "success" of the query, not the reason of the failure
                $this->videoFailed( $code !== 0 ? $data['base_resp']['status_msg'] ?? null : null );
            }

            $this->status( $data, $response );

            if( $status !== 'Success' ) {
                return false;
            }

            $fileId = $data['file_id'] ?? null;

            if( !is_string( $fileId ) && !is_int( $fileId ) ) {
                $this->videoFailed();
            }

            $result->add( $this->video( $fileId ) );
            return true;
        };
    }


    /**
     * Builds the MiniMax video generation request.
     *
     * @param string $prompt Video prompt
     * @param array<string, mixed> $media Input media by semantic role
     * @param array<string, mixed> $options Generation options
     * @return array<string, mixed> Request payload
     */
    protected function request( string $prompt, array $media, array $options ) : array
    {
        $start = $media['start'] ?? null;
        $end = $media['end'] ?? null;
        $subjects = $this->subjects( $media );

        $default = !empty( $subjects ) ? 'S2V-01'
            : ( $start instanceof Image && $end instanceof Image ? 'MiniMax-Hailuo-02' : 'MiniMax-Hailuo-2.3' );
        $request = [
            'model' => $this->modelName( $default ),
            'prompt' => $prompt,
        ];

        if( $start instanceof Image ) {
            $request['first_frame_image'] = $this->mediaUrl( $start );

            if( $end instanceof Image ) {
                $request['last_frame_image'] = $this->mediaUrl( $end );
            }
        } elseif( !empty( $subjects ) ) {
            $request['subject_reference'] = [[
                'type' => 'character',
                'image' => $subjects,
            ]];
        }

        $map = [
            'duration' => 'duration',
            'resolution' => 'resolution',
            'seed' => 'seed',
            'prompt_optimizer' => 'prompt_optimizer',
        ];

        foreach( $map as $source => $target ) {
            if( isset( $options[$source] ) ) {
                $request[$target] = $options[$source];
            }
        }

        return $request;
    }


    /**
     * Throws the typed exception for errors MiniMax reports in responses with HTTP status 200.
     *
     * The errors are temporary or caused by the request, so they aren't reported as failed jobs.
     *
     * @param array<string, mixed> $data Response data with the "base_resp" status
     * @param ResponseInterface $response HTTP response for the Retry-After header of rate limit errors
     * @throws PrismaException If the status code isn't zero
     */
    protected function status( array $data, ResponseInterface $response ) : void
    {
        $base = is_array( $data['base_resp'] ?? null ) ? $data['base_resp'] : [];
        $code = is_numeric( $base['status_code'] ?? null ) ? (int) $base['status_code'] : 0;

        if( $code === 0 ) {
            return;
        }

        $msg = is_string( $base['status_msg'] ?? null ) && $base['status_msg'] !== ''
            ? $base['status_msg']
            : 'MiniMax error ' . $code;

        $this->throw( self::ERRORS[$code] ?? 500, $msg, $response );
    }


    /**
     * Returns supported MiniMax subject reference URLs.
     *
     * @param array<string, mixed> $media Input media by semantic role
     * @return array<int, string> Subject image URLs
     */
    protected function subjects( array $media ) : array
    {
        if( ( $media['start'] ?? null ) instanceof Image ) {
            return [];
        }

        $subjects = [];
        $references = is_array( $media['references'] ?? null ) ? $media['references'] : [];

        foreach( $references as $reference ) {
            if( $reference instanceof Image ) {
                $subjects[] = $this->mediaUrl( $reference );
            }
        }

        return $subjects;
    }


    /**
     * Retrieves a completed MiniMax video file.
     *
     * @param string|int $id File identifier
     * @return Video Generated video
     */
    protected function video( string|int $id ) : Video
    {
        $response = $this->client()->get( 'v1/files/retrieve?file_id=' . rawurlencode( (string) $id ) );
        $this->validate( $response );

        /** @var array<string, mixed> $data */
        $data = $this->fromJson( $response );
        $this->status( $data, $response );

        /** @var array<string, mixed> $file */
        $file = is_array( $data['file'] ?? null ) ? $data['file'] : [];
        $url = $file['download_url'] ?? null;

        if( !is_string( $url ) || $url === '' ) {
            $this->videoFailed();
        }

        return Video::fromUrl( $url, 'video/mp4' );
    }
}
