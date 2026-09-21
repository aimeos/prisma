<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Demix;
use Aimeos\Prisma\Contracts\Audio\Denoise;
use Aimeos\Prisma\Contracts\Audio\Revoice;
use Aimeos\Prisma\Contracts\Audio\Speak;
use Aimeos\Prisma\Contracts\Audio\Transcribe;
use Aimeos\Prisma\Contracts\Resume;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\FileResponse;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Audiopod extends Base implements Demix, Denoise, Resume, Revoice, Speak, Transcribe
{
    /** Status paths of the jobs, the key of the result URLs in their status data and the prefix of result paths */
    private const JOBS = [
        'api/v1/stem-extraction/status/%s' => ['download_urls'],
        'api/v1/denoiser/jobs/%s' => ['output_url'],
        'api/v1/voice/tts-jobs/%s/status' => ['output_url'],
        'api/v1/voice/convert/%s/status' => ['output_path', 'https://media.audiopod.ai/'],
    ];

    /** Status path of transcription jobs, which return text instead of files */
    private const TRANSCRIPTION = 'api/v1/transcription/jobs/%s';


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'X-API-Key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.audiopod.ai' ) );
    }


    public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse
    {
        foreach( [1, 2, 4, 6, 8, 12, 16] as $num ) {
            if( $stems <= $num ) {
                $stems = $num;
                break;
            }
        }

        $mode = match( $stems ) {
            2 => 'two',
            4 => 'four',
            6 => 'six',
            8 => 'producer',
            12 => 'studio',
            16 => 'mastering',
            default => 'single'
        };

        $params = ['mode' => $mode];

        if( $stems === 1 ) {
            $params['stem'] = 'vocals';
        }

        if( $audio->url() ) {
            $request = $this->payload( ['url' => $audio->url()] + $params );
        } else {
            $request = $this->payload( $params, ['file' => $audio] );
        }

        $response = $this->client()->post( "api/v1/stem-extraction/api/extract", ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        return $this->queued( 'api/v1/stem-extraction/status/%s', $id );
    }


    public function denoise( Audio $audio, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['quality_mode'] ) + ['quality_mode' => 'balanced'];

        if( $audio->url() ) {
            $request = ['json' => $this->payload( ['url' => $audio->url()] + $allowed )];
        } else {
            $request = ['multipart' => $this->payload( $allowed, ['file' => $audio] )];
        }

        $response = $this->client()->post( "api/v1/denoiser/denoise", $request );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        return $this->queued( 'api/v1/denoiser/jobs/%s', $id );
    }


    /**
     * Resumes polling a demix, denoise, revoice, speak or transcribe job.
     *
     * @param string $jobId Status path of the job returned by jobId()
     * @return FileResponse|TextResponse Asynchronous audio response, a text response for transcriptions
     * @throws BadRequestException If the job ID isn't a status path of a resumable job
     */
    public function resume( string $jobId ) : FileResponse|TextResponse
    {
        // Transcriptions return the text of the job instead of a file
        if( ( $id = $this->pathId( self::TRANSCRIPTION, $jobId ) ) !== null ) {
            return $this->transcript( $id );
        }

        return $this->job( $jobId );
    }


    public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse
    {
        $request = $this->payload( ['voice_uuid' => $voice], ['file' => $audio] );
        $response = $this->client()->post( 'api/v1/voice/voice-convert', ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $id = $this->toData( $response, 'id' )['id'];
        return $this->queued( 'api/v1/voice/convert/%s/status', $id );
    }


    public function speak( string $text, ?string $voice = null, array $options = [] ) : FileResponse
    {
        $allowed = $this->allowed( $options, ['audio_format', 'language', 'speed'] );
        $selected = $voice ?: 'b76f1226-8170-4902-9482-36bb4fc98085'; // fallback: aura

        $request = $this->payload( ['input_text' => $text] + $allowed + ['audio_format' => 'mp3'] );
        $response = $this->client()->post( "api/v1/voice/voices/{$selected}/generate", ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $jobId = $this->toData( $response, 'job_id' )['job_id'];
        return $this->queued( 'api/v1/voice/tts-jobs/%s/status', $jobId );
    }


    public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, [
            'enable_confidence_scores', 'enable_speaker_diarization', 'enable_word_timestamps'
        ] );

        $files = [];
        $request = [
            'model_type' => $this->modelName( 'faster-whisper' ),
        ] + $allowed + ['enable_word_timestamps' => 0];

        if( $lang ) {
            $request['language'] = $lang;
        }

        if( $audio->url() ) {
            $url = 'api/v1/transcription/transcribe';
            $request['source_urls'] = [$audio->url()];
        } else {
            $url = 'api/v1/transcription/transcribe-upload';
            $files = ['files' => $audio];
        }

        $request = $this->payload( $request, $files );
        $response = $this->client()->post( $url, ['multipart' => $request] );

        $this->validate( $response );

        /** @var string */
        $jobId = $this->toData( $response, 'job_id' )['job_id'];
        return $this->transcript( $jobId );
    }


    /**
     * Tests if the job is completed.
     *
     * @param array<string|int, mixed> $data Job status data
     * @return bool TRUE if the job is completed, FALSE if it's still running
     * @throws FailedException If the job failed or was canceled
     */
    protected function completed( array $data ) : bool
    {
        // text to speech jobs report their status in lower case and the error in "error_message"
        $status = is_string( $data['status'] ?? null ) ? strtoupper( $data['status'] ) : null;

        if( in_array( $status, ['FAILED', 'CANCELLED'], true ) )
        {
            $error = $data['error_message'] ?? $data['error'] ?? null;
            throw new FailedException( is_string( $error ) && $error !== '' ? $error : 'Audiopod job failed' );
        }

        return $status === 'COMPLETED';
    }


    protected function getResponse( string $url ) : ResponseInterface
    {
        $response = $this->client()->get( $url );
        $this->validate( $response );

        return $response;
    }


    /**
     * Returns the response polling a file based job.
     *
     * @param string $jobId Status path of the job returned by jobId()
     * @return FileResponse Asynchronous audio response
     * @throws BadRequestException If the job ID isn't a status path of a resumable job
     */
    protected function job( string $jobId ) : FileResponse
    {
        foreach( array_keys( self::JOBS ) as $path )
        {
            if( ( $id = $this->pathId( $path, $jobId ) ) !== null ) {
                return $this->queued( $path, $id );
            }
        }

        throw new BadRequestException( 'Invalid Audiopod job ID' );
    }


    /**
     * Returns the ID of the provider if the job ID is the given status path.
     *
     * Job IDs are caller input, so the ID must be a single path segment without a query. It's
     * decoded to be URL encoded again, which keeps it inside that segment, and an ID of dots
     * only is rejected because it would still reach another endpoint with the API key.
     *
     * @param string $path Status path with a placeholder for the ID
     * @param string $jobId Status path of the job returned by jobId()
     * @return string|null Decoded ID of the provider or NULL if the job ID is another status path
     * @throws BadRequestException If the ID consists of dots only
     */
    protected function pathId( string $path, string $jobId ) : ?string
    {
        $pattern = '#^' . str_replace( '%s', '([^/?\#]+)', preg_quote( $path, '#' ) ) . '$#D';
        return preg_match( $pattern, $jobId, $matches ) ? $this->jobId( rawurldecode( $matches[1] ) ) : null;
    }


    /**
     * Returns a closure that polls a job and adds its result files.
     *
     * @param string $path Status path of the job
     * @param string $key Key of the result URL or URLs in the status data
     * @param string $prefix Prefix of results that are storage keys instead of URLs, e.g. the media host
     * @return \Closure Polling closure populating the file response
     * @throws FailedException If the job failed, was canceled or finished without results
     */
    protected function poll( string $path, string $key, string $prefix = '' ) : \Closure
    {
        return function( FileResponse $fr ) use ( $path, $key, $prefix ) : bool {

            $data = $this->toData( $this->getResponse( $path ) );
            $fr->withMeta( $data );

            if( !$this->completed( $data ) ) {
                return false;
            }

            // single URL or URLs by stem name, entries that aren't URLs don't become files
            $urls = array_filter( (array) ( $data[$key] ?? [] ), fn( $url ) => is_string( $url ) && $url !== '' );

            if( empty( $urls ) ) {
                throw new FailedException( 'No audio in Audiopod response' );
            }

            foreach( $urls as $name => $url ) {
                $fr->add( Audio::fromUrl( $prefix . $url ), $name );
            }

            return true;
        };
    }


    /**
     * Returns the response polling a job of the provider.
     *
     * @param string $path Status path of the job from JOBS with a placeholder for the ID
     * @param string $id ID of the job returned by the provider, URL encoded here
     * @return FileResponse Asynchronous audio response
     */
    protected function queued( string $path, string $id ) : FileResponse
    {
        $jobId = sprintf( $path, rawurlencode( $this->jobId( $id ) ) );
        return FileResponse::fromAsync( $this->poll( $jobId, ...self::JOBS[$path] ), 3, jobId: $jobId );
    }


    /**
     * Convert the response body to an associative array and validate the presence of a required key if specified.
     *
     * @param ResponseInterface $response The response to convert
     * @param string|null $key Optional key to validate in the response data
     * @return array<string, mixed> The response data as an associative array
     * @throws PrismaException If the response body is not valid JSON or if the required key is missing
     */
    protected function toData( ResponseInterface $response, ?string $key = null ) : array
    {
        $data = $this->fromJson( $response );

        if( $key && !isset( $data[$key] ) ) {
            throw new PrismaException( sprintf( 'Required key "%1$s" missing: %2$s', $key, print_r( $data, true ) ) );
        }

        return $data;
    }


    /**
     * Returns the response polling a transcription job.
     *
     * @param string $id ID of the job returned by the provider, URL encoded here
     * @return TextResponse Asynchronous text response
     */
    protected function transcript( string $id ) : TextResponse
    {
        $id = rawurlencode( $this->jobId( $id ) );
        return TextResponse::fromAsync( $this->transcription( $id ), 3, jobId: sprintf( self::TRANSCRIPTION, $id ) );
    }


    protected function transcription( string $id ) : \Closure
    {
        return function( TextResponse $tr ) use ( $id ) : bool {

            $data = $this->toData( $this->getResponse( sprintf( self::TRANSCRIPTION, $id ) ) );
            $tr->withMeta( $data );

            if( !$this->completed( $data ) ) {
                return false;
            }

            $data = $this->toData( $this->getResponse( "api/v1/transcription/transcript/{$id}" ) );

            /** @var array<int, array<string, mixed>> */
            $segments = $data['segments'] ?? [];

            $text = join( ' ', array_map( function( $segment ) {
                return $segment['text'];
            }, $segments ) );

            /** @var array<string, mixed> */
            $statistics = $data['statistics'] ?? [];

            // withMeta() replaces the meta data, so keep the fields of the job status too
            $tr->add( $text )
                ->withStructured( $segments )
                ->withMeta( $statistics + $tr->meta()->all() );

            return true;
        };
    }
}
