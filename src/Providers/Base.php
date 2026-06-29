<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\HasHttpClient;
use Aimeos\Prisma\Concerns\HasHttpResponse;
use Aimeos\Prisma\Concerns\HasHttpStream;
use Aimeos\Prisma\Concerns\HasMessages;
use Aimeos\Prisma\Concerns\HasModel;
use Aimeos\Prisma\Concerns\HasSystemPrompt;
use Aimeos\Prisma\Concerns\HasTokens;
use Aimeos\Prisma\Concerns\HasTools;
use Aimeos\Prisma\Contracts\Provider;
use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Exceptions\NotImplementedException;
use Aimeos\Prisma\Files\File;


/**
 * Abstract base class for all AI providers.
 *
 * Provides common functionality for HTTP communication, model selection,
 * system prompts, tool handling, and request/response processing.
 * Concrete providers extend this class and implement capability-specific
 * contracts (e.g. Text\Write, Image\Generate, Audio\Speech).
 */
abstract class Base implements Provider
{
    use HasHttpClient;
    use HasHttpResponse;
    use HasHttpStream;
    use HasMessages;
    use HasModel;
    use HasSystemPrompt;
    use HasTokens;
    use HasTools;


    protected const GEN = 'generation';
    protected const STRUCT = 'structured';


    /** @var array<string, array<string, mixed>> */
    protected const PROVIDER_TOOL_MAP = [];


    /**
     * Handles calls to undefined methods.
     *
     * @param array<int, mixed> $arguments Method arguments
     */
    public function __call( string $method, array $arguments ) : mixed
    {
        throw new NotImplementedException( sprintf( '"%1$s" does not implement "%2$s"', get_class( $this), $method ) );
    }


    /**
     * Ensures that the provider implements the given method.
     *
     * @param string $method Method name to check (e.g. 'stream', 'generate')
     * @return static Same provider instance for fluent calls
     * @throws \Aimeos\Prisma\Exceptions\NotImplementedException If the method is not implemented
     */
    public function ensure( string $method ) : static
    {
        if( !$this->has( $method ) ) {
            throw new NotImplementedException( sprintf( '"%1$s" does not implement "%2$s"', get_class( $this ), $method ) );
        }

        return $this;
    }


    /**
     * Tests if the provider implements the given method.
     *
     * @param string $method Method name to check (e.g. 'stream', 'generate')
     * @return bool TRUE if the capability is implemented, FALSE otherwise
     */
    public function has( string $method ) : bool
    {
        $type = current( array_slice( explode( '\\', get_class( $this ) ), -2, 1 ) );
        $name = '\\Aimeos\\Prisma\\Contracts\\' . $type . '\\' . ucfirst( $method );

        if( !interface_exists( $name ) ) {
            return false;
        }

        if( !( $this instanceof $name ) ) {
            return false;
        }

        return true;
    }


    /**
     * Filters options to only include allowed keys.
     *
     * @param array<string, mixed> $options All options
     * @param array<int, string> $allowed Allowed option keys
     * @return array<string, mixed> Filtered options
     */
    protected function allowed( array $options, array $allowed ) : array
    {
        return array_intersect_key( $options, array_flip( $allowed ) );
    }


    /**
     * Extracts a string value from a config array.
     *
     * @param array<string, mixed> $config Configuration array
     * @param string $key Configuration key
     * @param string $default Default value
     * @return string Configuration value as string
     */
    protected function config( array $config, string $key, string $default = '' ) : string
    {
        return isset( $config[$key] ) && is_string( $config[$key] ) ? $config[$key] : $default;
    }


    /**
     * Decodes a tool-call arguments JSON string into an array.
     *
     * Strips raw control characters that some models emit (which would otherwise make
     * json_decode() fail) and coerces any non-array result (e.g. a literal "null") to an
     * empty array so callers always receive a usable arguments map.
     *
     * @param string|null $json Tool-call arguments as a JSON string
     * @return array<string, mixed> Decoded arguments
     */
    protected function jsonArgs( ?string $json ) : array
    {
        $clean = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $json );
        $decoded = json_decode( (string) $clean, true );

        return is_array( $decoded ) ? $decoded : [];
    }


    /**
     * Adapts the JSON Schema array to the provider's structured output requirements.
     *
     * Providers override this method to adapt the schema to their own structured
     * output requirements (e.g. closing objects or dropping unsupported keys).
     *
     * @param array<string, mixed> $schema JSON Schema definition
     * @return array<string, mixed> Adapted JSON Schema definition
     */
    protected function jsonSchema( array $schema ) : array
    {
        return $schema;
    }


    /**
     * Decodes the JSON object from a model response, ignoring surrounding code fences.
     *
     * @param string|null $text Model response text
     * @return array<string, mixed> Decoded JSON, or an empty array if invalid
     */
    protected function parseJson( ?string $text ) : array
    {
        $text = trim( (string) $text );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', $text ) ?? $text;
        $data = json_decode( $text, true );

        return is_array( $data ) ? $data : [];
    }


    /**
     * Builds multipart form data for file upload requests.
     *
     * @param array<string, mixed> $options Request options
     * @param array<string, mixed> $files Files to upload
     * @return array<int, array<string, mixed>> Multipart form data
     */
    protected function payload( array $options, array $files = [] ) : array
    {
        $data = [];

        foreach( $options as $key => $val ) {
            $data[] = ['name' => $key, 'contents' => $val];
        }

        foreach( $files as $name => $entry )
        {
            if( !$entry ) {
                continue;
            }

            if( is_array( $entry ) )
            {
                foreach( $entry as $i => $file )
                {
                    if( !( $file instanceof File ) ) {
                        throw new BadRequestException( sprintf( 'Invalid file object for "%s"', $name ) );
                    }

                    $data[] = [
                        'name' => $name . "[$i]",
                        'contents' => $file->binary(),
                        'filename' => $file->filename() ?: "file-$i",
                        'headers'  => [
                            'Content-Type' => $file->mimeType()
                        ]
                    ];
                }
            }
            else
            {
                if( !( $entry instanceof File ) ) {
                    throw new BadRequestException( sprintf( 'Invalid file object for "%s"', $name ) );
                }

                $data[] = [
                    'name' => $name,
                    'contents' => $entry->binary(),
                    'filename' => $entry->filename() ?: 'file',
                    'headers'  => [
                        'Content-Type' => $entry->mimeType()
                    ]
                ];
            }
        }

        return $data;
    }


    /**
     * Removes options with invalid values.
     *
     * @param array<string, mixed> $options Options to sanitize
     * @param array<string, array<int, mixed>|null> $allowed Map of option names to valid values
     * @return array<string, mixed> Sanitized options
     */
    protected function sanitize( array $options, array $allowed ) : array
    {
        foreach( $allowed as $name => $values )
        {
            if( !is_null( $values ) && isset( $options[$name] ) && !in_array( $options[$name], $values ) ) {
                unset( $options[$name] );
            }
        }

        return $options;
    }
}
