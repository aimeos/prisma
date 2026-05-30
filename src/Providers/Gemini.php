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

        $this->header( 'x-goog-api-key', $this->cfg( $config, 'api_key' ) );
        $this->baseUrl( $this->cfg( $config, 'url', 'https://generativelanguage.googleapis.com' ) );
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

            $parts[] = [
                'functionResponse' => [
                    'name' => $step->name(),
                    'response' => json_decode( $result, true ) ?? ['result' => $result],
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
                $declaration['parameters'] = $schema;
            }

            $declarations[] = $declaration;
        }

        // Gemini doesn't support provider tools and custom tools in parallel, so
        // custom tools take precedence and provider tools are only sent without them.
        if( $declarations ) {
            return [['functionDeclarations' => $declarations]];
        }

        $providerToolMap = [
            'web_search' => ['google_search' => (object) [], 'options' => []],
            'code_execution' => ['code_execution' => (object) [], 'options' => []],
        ];

        return $this->mapProviderTools( $providerToolMap );
    }


    /**
     * Parses tool calls from Gemini API response.
     *
     * @param array<string, mixed> $result API response data
     * @return array<int, array{id: string|null, name: string, arguments: array<string, mixed>}> Parsed tool calls
     */
    protected function parseToolCalls( array $result ) : array
    {
        $toolCalls = [];
        $counts = [];

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


    protected function validate( ResponseInterface $response ) : void
    {
        if( ( $status = $response->getStatusCode() ) !== 200 )
        {
            $json = @$this->fromJson( $response );
            /** @var array<string, mixed> $errorObj */
            $errorObj = $json['error'] ?? [];
            /** @var string $error */
            $error = $errorObj['message'] ?? $response->getReasonPhrase();

            $this->throw( match( $status ) {
                403 => 401, // unauthorized, not forbidden content
                default   => $status
            }, $error );
        }
    }
}
