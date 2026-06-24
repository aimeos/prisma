<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Exceptions\PrismaException;
use Psr\Http\Message\ResponseInterface;


class Gemini extends Base
{
    use CallsTools;


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
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $json = @$this->fromJson( $response );
            /** @var array<string, mixed> $errorObj */
            $errorObj = $json['error'] ?? [];
            /** @var string $error */
            $error = $errorObj['message'] ?? $response->getReasonPhrase();

            // Gemini returns the retry delay in the 429 body (RetryInfo) rather than a
            // header, so it is parsed here and attached to the rate-limit exception.
            if( $status === 429 )
            {
                /** @var array<int, array<string, mixed>> $details */
                $details = $errorObj['details'] ?? [];
                throw ( new \Aimeos\Prisma\Exceptions\RateLimitException( is_string( $error ) ? $error : '' ) )
                    ->withRetryAfter( $this->retryDelay( $details ) );
            }

            $this->throw( match( $status ) {
                403 => 401, // unauthorized, not forbidden content
                default   => $status
            }, $error );
        }
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
}
