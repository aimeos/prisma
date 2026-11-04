<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Exceptions\FailedException;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\File;
use Aimeos\Prisma\Responses\TextResponse;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base
{
    use CallsTools;


    /** Media up to this size are sent inline because the base64 encoded request must stay below 20 MB */
    private const INLINE_BYTES = 14 * 1024 * 1024;

    /** Size of the uploaded parts, a multiple of the 8 MiB upload granularity of Gemini */
    private const PART_BYTES = 32 * 1024 * 1024;

    /** Polling interval in seconds until uploaded media are processed */
    private const RETRY = 3;

    /** @var \WeakMap<File, array{name: string, uri: string, mimeType: string, state: string|null}>|null Uploaded files, so files used several times are uploaded once */
    private ?\WeakMap $uploads = null;


    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'x-goog-api-key', $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://generativelanguage.googleapis.com' ) );
    }


    /**
     * Builds content parts with images and text in Gemini format.
     *
     * @param string $prompt Text prompt
     * @param array<int, \Aimeos\Prisma\Files\File> $files Image files
     * @return array<int, array<string, mixed>> Content parts
     */
    protected function content( string $prompt, array $files ) : array
    {
        $parts = array_map( fn( \Aimeos\Prisma\Files\File $file ) => [
            'inlineData' => [
                'data' => $file->base64(),
                'mimeType' => $file->mimeType()
            ],
        ], $files );

        $parts[] = ['text' => $prompt];

        return $parts;
    }


    /**
     * Extracts text content from Gemini candidate parts.
     *
     * @param array<int, array<string, mixed>> $candidates Candidate response blocks
     * @return array<int, string> Extracted texts
     */
    protected function candidateTexts( array $candidates ) : array
    {
        $texts = [];

        foreach( $candidates as $candidate )
        {
            /** @var array<int, array<string, mixed>> $parts */
            $parts = $candidate['content']['parts'] ?? [];

            foreach( $parts as $part )
            {
                if( !( $part['thought'] ?? false ) && ( $text = $part['text'] ?? null ) ) {
                    $texts[] = $text;
                }
            }
        }

        return $texts;
    }


    /**
     * Decodes JSON-string arguments back into the objects/arrays the schema expects.
     *
     * This is the inverse of encodeArgs(): that method declares schema-less object
     * parameters as strings so the model can emit real content for them, and this restores
     * the structure before the tool runs. It is schema-driven, so populated objects/arrays
     * pass through untouched.
     *
     * @param mixed $value Argument value from the model
     * @param array<string, mixed> $schema JSON Schema definition for the value
     * @return mixed Decoded value
     */
    protected function decodeArgs( mixed $value, array $schema ) : mixed
    {
        $type = $schema['type'] ?? null;

        if( in_array( $type, ['object', 'array'], true ) && is_string( $value ) )
        {
            $decoded = json_decode( $value, true );

            if( json_last_error() === JSON_ERROR_NONE ) {
                $value = $decoded;
            }
        }

        if( $type === 'object' && is_array( $value ) && is_array( $schema['properties'] ?? null ) )
        {
            foreach( $schema['properties'] as $key => $propSchema )
            {
                if( array_key_exists( $key, $value ) && is_array( $propSchema ) ) {
                    $value[$key] = $this->decodeArgs( $value[$key], $propSchema );
                }
            }
        }

        if( $type === 'array' && is_array( $value ) && is_array( $schema['items'] ?? null ) ) {
            $value = array_map( fn( $v ) => $this->decodeArgs( $v, $schema['items'] ), $value );
        }

        return $value;
    }


    /**
     * Declares schema-less object nodes as JSON strings for Gemini function calls.
     *
     * Gemini fills an object parameter that declares no properties with an empty "{}" because
     * its structured output has no fields to populate. Declaring such free-form objects as
     * strings lets the model emit real content; decodeArgs() restores the JSON string back
     * into an object before the tool runs.
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> Schema with free-form objects turned into strings
     */
    protected function encodeArgs( array $schema ) : array
    {
        if( ( $schema['type'] ?? null ) === 'object' && empty( $schema['properties'] ) )
        {
            return [
                'type' => 'string',
                'description' => trim( ( $schema['description'] ?? '' ) . ' Provide as a JSON-encoded object.' ),
            ];
        }

        if( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
            $schema['properties'] = array_map( fn( array $prop ) => $this->encodeArgs( $prop ), $schema['properties'] );
        }

        if( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
            $schema['items'] = $this->encodeArgs( $schema['items'] );
        }

        if( isset( $schema['$defs'] ) && is_array( $schema['$defs'] ) ) {
            $schema['$defs'] = array_map( fn( array $sub ) => $this->encodeArgs( $sub ), $schema['$defs'] );
        }

        return $schema;
    }


    /**
     * Maps the conversation history to Gemini contents.
     *
     * @return array<int, array<string, mixed>> History contents
     */
    protected function mapMessages() : array
    {
        $contents = [];

        foreach( $this->history() as $msg )
        {
            $contents[] = $msg['role'] === 'assistant'
                ? ['role' => 'model', 'parts' => [['text' => $msg['content']]]]
                : ['role' => 'user', 'parts' => $this->content( $msg['content'], $msg['files'] )];
        }

        return $contents;
    }


    /**
     * Parses tool calls from Gemini API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function toolCalls( array $result ) : array
    {
        $toolCalls = [];
        $counts = [];

        $schemas = [];
        foreach( $this->tools() as $tool ) {
            $schemas[$tool->name()] = $tool->schema()->toArray();
        }

        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = $result['candidates'] ?? [];

        foreach( $candidates as $candidate )
        {
            /** @var array<string, mixed> $candidateContent */
            $candidateContent = $candidate['content'] ?? [];
            /** @var array<int, array<string, mixed>> $parts */
            $parts = $candidateContent['parts'] ?? [];

            foreach( $parts as $part )
            {
                if( isset( $part['functionCall'] ) ) {
                    /** @var array<string, mixed> $fnCall */
                    $fnCall = $part['functionCall'];
                    /** @var string $name */
                    $name = $fnCall['name'] ?? '';
                    /** @var array<string, mixed> $args */
                    $args = $fnCall['args'] ?? [];

                    // Reverse encodeArgs(): the model returns schema-less object parameters
                    // as JSON strings, so decode them back into the structure the tool expects.
                    if( isset( $schemas[$name] ) ) {
                        $args = $this->decodeArgs( $args, $schemas[$name] );
                    }

                    // decodeArgs() or a malformed functionCall can yield a scalar; the
                    // downstream arguments contract requires an array.
                    $args = is_array( $args ) ? $args : [];

                    // Gemini has no tool call id, so the name is used and a number is
                    // appended when the same tool is called more than once in a response.
                    $count = $counts[$name] = ( $counts[$name] ?? 0 ) + 1;

                    $toolCalls[] = [
                        'id' => $name . '-' . $count,
                        'name' => $name,
                        'arguments' => $args,
                    ];
                }
            }
        }

        return $toolCalls;
    }


    /**
     * Builds tool result messages in Gemini format.
     *
     * @param array<int, \Aimeos\Prisma\Tools\Step> $results Tool execution results
     * @return array<int, array<string, mixed>> Formatted tool result messages
     */
    protected function toolResults( array $results ) : array
    {
        $parts = [];

        foreach( $results as $step )
        {
            $result = $step->result();

            // Gemini's functionResponse.response must be a Struct (JSON object), so the
            // result is always wrapped under a "result" key. This keeps a uniform envelope
            // and avoids a "Proto field is not repeating" error when a tool returns a list.
            $parts[] = [
                'functionResponse' => [
                    'name' => $step->name(),
                    'response' => ['result' => json_decode( $result, true ) ?? $result],
                ],
            ];
        }

        return [['role' => 'user', 'parts' => $parts]];
    }


    /**
     * Asks the LLM about a media file.
     *
     * @param File $file Image, audio or video file
     * @param string $prompt Task prompt
     * @param string $model Default model name
     * @param array<string, mixed> $options Provider specific options
     * @return TextResponse Text parts of the first candidate, asynchronous if the file is uploaded
     */
    protected function understand( File $file, string $prompt, string $model, array $options ) : TextResponse
    {
        $config = $this->allowed( $options, ['temperature', 'topP', 'topK', 'thinkingConfig'] );

        // URLs are downloaded to a temporary stream once, so their size is known without keeping them in memory
        if( $file->bytes() === null && $file->url() ) {
            $file = $file::fromStream( $file->stream(), $file->mimeType() );
        }

        if( ( $size = $file->bytes() ) !== null && $size <= self::INLINE_BYTES ) {
            return $this->answer( $model, $this->content( $prompt, [$file] ), $config, TextResponse::fromTexts( [] ) );
        }

        $this->uploads ??= new \WeakMap();
        $upload = $this->uploads[$file] ??= $this->upload( $file );
        $parts = [['fileData' => ['fileUri' => $upload['uri'], 'mimeType' => $upload['mimeType']]], ['text' => $prompt]];
        $state = $upload['state'];

        // uploaded audio and video files are processed by Gemini before they can be used
        return TextResponse::fromAsync( function( TextResponse $response ) use ( $upload, $parts, $model, $config, &$state ) : bool {

            if( $state !== 'ACTIVE' && ( $state = $this->state( $upload['name'] ) ) !== 'ACTIVE' ) {
                return false;
            }

            $this->answer( $model, $parts, $config, $response );
            return true;
        }, self::RETRY );
    }


    /**
     * Builds the tools parameter in Gemini format.
     *
     * @return array<int, array<string, mixed>> Formatted tools definition
     */
    protected function toolsParam() : array
    {
        $declarations = [];

        foreach( $this->tools() as $tool )
        {
            $declaration = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];

            // Gemini rejects a parameters object of type "object" without properties,
            // so the field is omitted entirely for tools that take no arguments.
            if( !empty( ( $schema = $tool->schema()->toArray() )['properties'] ) ) {
                $declaration['parameters'] = $this->encodeArgs( $schema );
            }

            $declarations[] = $declaration;
        }

        $providerToolMap = [
            'web_search' => ['google_search' => (object) [], 'options' => []],
            'web_fetch' => ['url_context' => (object) [], 'options' => []],
            'code_execution' => ['code_execution' => (object) [], 'options' => []],
        ];

        $tools = $this->mapProviderTools( $providerToolMap );

        // Gemini 3+ allows custom function tools alongside provider tools; the function
        // declarations are sent as an additional tools entry while the request enables
        // includeServerSideToolInvocations (see generateRequest()) so both work together.
        if( $declarations ) {
            array_unshift( $tools, ['functionDeclarations' => $declarations] );
        }

        return $tools;
    }


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) < 200 || $status >= 300 )
        {
            $json = $this->errorData( $response );
            $error = $this->errorMessage( $json ) ?? $response->getReasonPhrase();

            // Gemini returns the retry delay in the 429 body (RetryInfo) rather than a
            // header, so it is parsed here and attached to the rate-limit exception.
            // Proxies in front of the API may send the Retry-After header instead.
            if( $status === 429 )
            {
                /** @var array<int, array<string, mixed>> $details */
                $details = is_array( $json['error']['details'] ?? null ) ? $json['error']['details'] : [];
                throw ( new \Aimeos\Prisma\Exceptions\RateLimitException( $error ) )
                    ->withRetryAfter( $this->retryDelay( $details ) ?? $this->retryHeader( $response ) );
            }

            $this->throw( match( $status ) {
                403 => 401, // unauthorized, not forbidden content
                default   => $status
            }, $error, $response );
        }
    }


    /**
     * Generates the answer to the media parts and adds it to the response.
     *
     * @param string $model Default model name
     * @param array<int, array<string, mixed>> $parts Media and prompt parts
     * @param array<string, mixed> $config Generation config
     * @param TextResponse $response Response to populate
     * @return TextResponse Populated response
     */
    private function answer( string $model, array $parts, array $config, TextResponse $response ) : TextResponse
    {
        $result = $this->client()->post( 'v1beta/models/' . $this->modelName( $model ) . ':generateContent', ['json' => [
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['TEXT']] + $config,
        ]] );

        $this->validate( $result );

        $data = $this->fromJson( $result );
        $candidate = is_array( $data['candidates'][0] ?? null ) ? $data['candidates'][0] : [];
        $texts = $this->candidateTexts( [$candidate] );
        $usage = is_array( $data['usageMetadata'] ?? null ) ? $data['usageMetadata'] : [];
        $used = $usage['totalTokenCount'] ?? null;

        return $response->add( $texts ? implode( '', $texts ) : null )
            ->withUsage( is_numeric( $used ) ? (float) $used : null, $usage )
            ->withMeta( is_array( $candidate['metadata'] ?? null ) ? $candidate['metadata'] : [] );
    }


    /**
     * Sends a part of the file content to the resumable upload URL.
     *
     * @param string $url Upload URL returned by Gemini
     * @param string $chunk Part of the file content
     * @param int $offset Position of the part in the file content
     * @param string $command Upload command, "upload" or "upload, finalize" for the last part
     * @return ResponseInterface Validated upload response
     */
    private function part( string $url, string $chunk, int $offset, string $command ) : ResponseInterface
    {
        $response = $this->client()->post( $url, [
            'headers' => [
                'X-Goog-Upload-Command' => $command,
                'X-Goog-Upload-Offset' => (string) $offset,
            ],
            'body' => $chunk,
        ] );

        $this->validate( $response );
        return $response;
    }


    /**
     * Extracts the retry delay (seconds) from a Gemini error's RetryInfo detail.
     *
     * @param array<int, array<string, mixed>> $details Error details from the response body
     * @return int|null Retry delay in seconds or null if absent
     */
    private function retryDelay( array $details ) : ?int
    {
        foreach( $details as $detail )
        {
            if( str_ends_with( (string) ( $detail['@type'] ?? '' ), 'RetryInfo' ) && isset( $detail['retryDelay'] ) ) {
                return (int) rtrim( (string) $detail['retryDelay'], 's' );
            }
        }

        return null;
    }


    /**
     * Returns the processing state of an uploaded file.
     *
     * @param string $name Resource name of the file, e.g. "files/abc-123"
     * @return string|null Processing state, e.g. "PROCESSING" or "ACTIVE"
     * @throws FailedException If Gemini failed to process the file
     */
    private function state( string $name ) : ?string
    {
        $response = $this->client()->get( 'v1beta/' . $name );
        $this->validate( $response );

        $state = $this->fromJson( $response )['state'] ?? null;

        if( $state === 'FAILED' ) {
            throw new FailedException( 'Gemini failed to process the uploaded file' );
        }

        return is_string( $state ) ? $state : null;
    }


    /**
     * Uploads the file to the Gemini Files API in parts.
     *
     * Large media can't be sent inline, so they are uploaded by the resumable upload protocol
     * which reads only one part at a time. Uploaded files are deleted by Gemini after 48 hours.
     *
     * @param File $file Input file
     * @return array{name: string, uri: string, mimeType: string, state: string|null} Uploaded file
     * @throws FailedException If the upload response is invalid
     */
    private function upload( File $file ) : array
    {
        $mimeType = $file->mimeType() ?? 'application/octet-stream';
        $headers = [
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Type' => $mimeType,
        ];

        if( ( $size = $file->bytes() ) !== null ) {
            $headers['X-Goog-Upload-Header-Content-Length'] = (string) $size;
        }

        $response = $this->client()->post( 'upload/v1beta/files', [
            'headers' => $headers,
            'json' => ['file' => ['display_name' => $file->filename() ?? 'upload']],
        ] );

        $this->validate( $response );

        if( !str_starts_with( $url = $response->getHeaderLine( 'X-Goog-Upload-URL' ), 'https://' ) ) {
            throw new FailedException( 'No upload URL in Gemini response' );
        }

        $offset = 0;
        $chunk = '';

        // the last part must finalize the upload, so each part is sent when the next one is read
        foreach( $file->chunks( self::PART_BYTES ) as $idx => $next )
        {
            if( $idx > 0 ) {
                $this->part( $url, $chunk, $offset, 'upload' );
                $offset += strlen( $chunk );
            }

            $chunk = $next;
        }

        $result = $this->fromJson( $this->part( $url, $chunk, $offset, 'upload, finalize' ) )['file'] ?? null;

        if( !is_array( $result ) || !is_string( $result['name'] ?? null ) || !is_string( $result['uri'] ?? null ) ) {
            throw new FailedException( 'No file in Gemini upload response' );
        }

        return [
            'name' => $result['name'],
            'uri' => $result['uri'],
            'mimeType' => is_string( $result['mimeType'] ?? null ) ? $result['mimeType'] : $mimeType,
            'state' => is_string( $result['state'] ?? null ) ? $result['state'] : null,
        ];
    }
}
